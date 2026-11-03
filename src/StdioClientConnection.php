<?php

namespace SuperconductorMCP\STDIO;

use Closure;
use Fabricate\Client\ClientConfiguration;
use Fabricate\Client\MCPClient;
use Fabricate\Contracts\Client\ClientConnection;
use Fabricate\Contracts\Schema\Messaging\MCPError;
use Fabricate\Contracts\Schema\RequestId;
use Fabricate\Contracts\Schema\RPCErrorCode;
use Fabricate\Mechanism\Decoding\DecodedBatch;
use Fabricate\Mechanism\Decoding\DecodedError;
use Fabricate\Mechanism\Decoding\DecodedRequest;
use Fabricate\Mechanism\Decoding\DecodedResponse;
use Fabricate\Mechanism\Decoding\InvalidMessage;
use Fabricate\Mechanism\Envelope;
use Fabricate\Mechanism\Mail\OutboundMessage;
use Fabricate\Mechanism\MessageDecoder;
use Fabricate\Mechanism\MessageHydrator;
use Fabricate\Schema\Common\Messaging\EmptyResult;
use Fabricate\Schema\V20241105;
use Fabricate\Schema\V20250326;
use Fabricate\Schema\V20250618;
use Fabricate\Schema\V20251125;
use Fabricate\Schema\V20260728;
use Fabricate\Schema\V20260728\Concerns\MCPErrorCode;
use Fabricate\Contracts\Schema\Messaging\Initialize\InitializeResult;
use RuntimeException;
use SuperconductorMCP\STDIO\Pipes\StdioClientPipe;
use Voyager\Contracts\IOPools\Loop;
use Voyager\NutsAndBolts\Str;

/**
 * A client's connection to a server it launches as a subprocess. Opening it runs the handshake here, never in the
 * entity: a client that supports 2026-07-28 probes with server/discover first, as the stdio transport asks, and a
 * server that answers it is modern. A recognized modern error ends the attempt; any other error, or silence until
 * mcp.clients.initialize_timeout, means a legacy server, which gets initialize in the newest version the client has
 * a handshake for, then notifications/initialized. The MCPClient is built in the agreed version only after that.
 *
 * Async mail the client's work posts to the loop reaches the session through deliver(), from whoever handles the
 * loop's mail. A 2026-07-28 server that exits unexpectedly is launched again, as the stdio transport asks; the
 * requests it was answering are lost.
 */
class StdioClientConnection implements ClientConnection
{
    /** Seconds the server gets to exit after each step of shutdown, before the next. */
    private const float EXIT_GRACE = 2.0;

    /** @var resource|null */
    private mixed $process = null;

    private ?StdioClientPipe $pipe = null;

    private readonly string $watcher;

    /** @var list<string> Lines from the server not yet handled: those read before the client exists. */
    private array $lines = [];

    private ?MCPClient $client = null;

    private ?string $failure = null;

    private bool $closing = false;

    /** What the server said about itself in the handshake: its InitializeResult, or on 2026-07-28 its DiscoverResult. */
    public private(set) InitializeResult|V20260728\Messaging\Discover\DiscoverResult|null $server = null;

    /**
     * @param list<string> $args
     * @param array<string, string> $env Added to this process's environment for the server.
     */
    public function __construct(
        public readonly ClientConfiguration $configuration,
        public readonly string $command,
        public readonly array $args = [],
        public readonly array $env = [],
    ) {
        // a process can hold several connections, so each pipe needs its own name
        $this->watcher = 'mcp.stdio.client.'.spl_object_id($this);
    }

    public function open(): void
    {
        $this->launch();

        if($version = $this->negotiate())
        {
            $this->client = new MCPClient($this->configuration, $version);

            // What the server sent in the same read as its handshake answer is the session's.
            foreach(array_splice($this->lines, 0) as $line) $this->receive($line);
        }
        else
        {
            $this->close();
        }
    }

    public function client(): ?MCPClient
    {
        return $this->client;
    }

    public function failure(): ?string
    {
        return $this->failure;
    }

    /**
     * Async mail for this connection's session: an answer or notification the client's work finished with.
     */
    public function deliver(OutboundMessage $mail): void
    {
        if($mail->session_id !== $this->client?->session_id) return;

        $this->client->queue($mail->message());
        $this->sendQueued();
    }

    /**
     * Shuts the server down as the stdio transport asks: its stdin closes, then it gets EXIT_GRACE seconds to exit,
     * then SIGTERM and the same again, then SIGKILL.
     */
    public function close(): void
    {
        $this->closing = true;
        $this->client?->close();
        $this->client = null;

        $this->stop();
    }

    private function launch(): void
    {
        $process = proc_open(
            [$this->command, ...$this->args],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            $this->env === [] ? null : [...getenv(), ...$this->env],
        );

        if(! is_resource($process)) throw new RuntimeException("Could not start [{$this->command}].");

        $this->process = $process;
        $this->failure = null;

        $this->pipe = new StdioClientPipe(
            ...$pipes,
            on_line: function(string $line) {
                $this->client ? $this->receive($line) : $this->lines[] = $line;
            },
            on_end: $this->ended(...),
            before_wait: $this->sendQueued(...),
        );

        app(Loop::class)->resource($this->watcher, $this->pipe);
    }

    /**
     * The version the server and client agree on, or null with the reason in failure().
     */
    private function negotiate(): ?string
    {
        if(in_array('2026-07-28', $this->configuration->supportedProtocolVersions(), true))
        {
            $modern = $this->discover();

            if($modern || $this->failure) return $modern;

            if($this->configuration->handshakeProtocolVersions() === [])
            {
                $this->failure = 'The server only speaks protocol versions with an initialize handshake, and this client supports none of them.';
                return null;
            }
        }

        return $this->initialize();
    }

    /**
     * Probes with server/discover in 2026-07-28: the version when the server answers that it supports it, null when it
     * is modern without it (failure() says why) or legacy (failure() stays null).
     */
    private function discover(): ?string
    {
        $id = new RequestId((string) Str::uuid());
        $meta = new V20260728\Messaging\MetaObjects\RequestMetaObject('2026-07-28', $this->configuration->capabilities('2026-07-28'), $this->configuration->implementation('2026-07-28'));

        $this->pipe->send(new V20260728\Messaging\Discover\DiscoverRequest($id, new V20260728\Messaging\Params\RequestParams($meta))->toJson());

        $answer = $this->await($id, '2026-07-28');

        if($answer instanceof DecodedResponse)
        {
            $result = MessageHydrator::hydrateAs($answer, '2026-07-28', 'server/discover');

            if(! $result instanceof V20260728\Messaging\Discover\DiscoverResult)
            {
                $this->failure = 'The server answered server/discover with a malformed result'.($result instanceof MCPError ? ': '.$result->toArray()['message'] : '.');
                return null;
            }

            if(! in_array('2026-07-28', $result->supportedVersions, true))
            {
                $this->failure = 'The server supports ['.implode(', ', $result->supportedVersions).'], and this client none of them.';
                return null;
            }

            $this->server = $result;

            return '2026-07-28';
        }

        // A recognized modern error means a modern server: no falling back to initialize.
        if($answer instanceof DecodedError && MCPErrorCode::tryFrom($answer->code))
        {
            $supported = implode(', ', (array) ($answer->data['supported'] ?? []));
            $this->failure = "The server refused server/discover: [{$answer->code}] {$answer->message}".($supported ? " It supports [{$supported}]." : '');
        }

        return null;
    }

    /**
     * Sends initialize in the newest version the client has a handshake for, and notifications/initialized once the
     * server answers in one the client supports. The client disconnects otherwise, as the lifecycle asks.
     */
    private function initialize(): ?string
    {
        $id = new RequestId((string) Str::uuid());
        $requested = $this->configuration->handshakeProtocolVersion();

        $this->pipe->send(Envelope::request($requested, $this->configuration->initializeRequest($id, $requested), $id)->toJson());

        $answer = $this->await($id, $requested);

        if($answer instanceof DecodedError)
        {
            $this->failure = "The server refused initialize: [{$answer->code}] {$answer->message}";
            return null;
        }

        if(! $answer instanceof DecodedResponse)
        {
            $this->failure ??= 'The server did not answer initialize within '.config('mcp.clients.initialize_timeout').' seconds.';
            return null;
        }

        $offered = $answer->result['protocolVersion'] ?? null;

        if(! is_string($offered) || ! in_array($offered, $this->configuration->handshakeProtocolVersions(), true))
        {
            $this->failure = 'The server answered initialize in protocol version ['.(is_string($offered) ? $offered : 'none').'], which this client does not support.';
            return null;
        }

        $result = MessageHydrator::hydrateAs($answer, $offered, 'initialize');

        if($result instanceof MCPError)
        {
            $this->failure = 'The server answered initialize with a malformed result: '.$result->toArray()['message'];
            return null;
        }

        $this->server = $result;

        $initialized = match($offered) {
            '2024-11-05' => new V20241105\Messaging\Initialize\InitializedNotification(),
            '2025-03-26' => new V20250326\Messaging\Initialize\InitializedNotification(),
            '2025-06-18' => new V20250618\Messaging\Initialize\InitializedNotification(),
            '2025-11-25' => new V20251125\Messaging\Initialize\InitializedNotification(),
        };

        $this->pipe->send(Envelope::notification($offered, $initialized)->toJson());

        return $offered;
    }

    /**
     * Waits for the response to a handshake request, until mcp.clients.initialize_timeout or the server ends. Pings
     * the server sends meanwhile are answered, which the lifecycle allows before initialized; nothing else is the
     * handshake's, and is dropped.
     */
    private function await(RequestId $id, string $protocol_version): DecodedResponse|DecodedError|null
    {
        $loop = app(Loop::class);
        $expired = false;
        $answer = null;

        // a quiet loop never re-checks the condition, so the timer is what wakes it
        $deadline = $loop->at((float) config('mcp.clients.initialize_timeout'), function() use (&$expired) {
            $expired = true;
        });

        try {
            $loop->until(function() use ($id, $protocol_version, &$expired, &$answer) {
                while(! is_null($line = array_shift($this->lines)))
                {
                    $message = MessageDecoder::fromWire($line);

                    if(($message instanceof DecodedResponse || $message instanceof DecodedError) && $message->id()?->id === $id->id)
                    {
                        $answer = $message;
                        return true;
                    }

                    if($message instanceof DecodedRequest && $message->method === 'ping' && $protocol_version !== '2026-07-28')
                    {
                        $this->pipe->send(Envelope::response($protocol_version, $message->id(), new EmptyResult())->toJson());
                    }
                }

                return $expired || ! is_null($this->failure);
            });
        } finally {
            $deadline->cancel();
        }

        return $answer;
    }

    /**
     * One line from the server, once the client exists: its message goes to the client, and an answer goes back.
     */
    private function receive(string $line): void
    {
        $message = MessageDecoder::fromWire($line);
        $version = $this->client->active_protocol_version;

        if($message instanceof InvalidMessage)
        {
            // On 2026-07-28 a stdio client must not write responses.
            if($version !== '2026-07-28') $this->pipe->send($message->toJson());
        }
        elseif($message instanceof DecodedBatch)
        {
            $this->receiveBatch($message);
        }
        elseif($reply = $this->client->receiveMessage($message))
        {
            $this->client->queue($reply);
        }

        $this->sendQueued();
    }

    /**
     * A batch, which only 2025-03-26 has: the answers to its requests go back as one batch. Other versions refuse it.
     */
    private function receiveBatch(DecodedBatch $batch): void
    {
        $version = $this->client->active_protocol_version;

        if($version !== '2025-03-26')
        {
            if($version !== '2026-07-28') $this->pipe->send(new InvalidMessage(null, RPCErrorCode::INVALID_REQUEST, "Batches are not part of {$version}.")->toJson());
            return;
        }

        $answers = [];

        foreach($batch->messages as $message)
        {
            $answer = $message instanceof InvalidMessage
                ? new V20250326\Messaging\RPC\JSONRPCError('2.0', $message->id(), $message->code->value, $message->message)
                : $this->client->receiveMessage($message);

            if($answer) $answers[] = $answer;
        }

        if($answers === []) return;

        $this->sendQueued();
        $this->pipe->send(new V20250326\Messaging\RPC\JSONRPCBatchResponse($answers)->toJson());
    }

    /**
     * Sends everything the client queued, oldest first.
     */
    private function sendQueued(): void
    {
        foreach($this->client?->pullQueued() ?? [] as $message) $this->pipe->send($message->toJson());
    }

    /**
     * The server can no longer be talked to. A 2026-07-28 session launches it again; any other session is over.
     */
    private function ended(string $reason): void
    {
        if($this->closing || ! is_null($this->failure)) return;

        $this->failure = $reason;

        if($this->client?->active_protocol_version === '2026-07-28')
        {
            // not from inside the pipe's own wake: the loop is still walking it
            app(Loop::class)->at(0, function() {
                if($this->closing) return;

                $this->stop();
                $this->launch();
            });

            return;
        }

        $this->client?->close();
        $this->client = null;
    }

    /**
     * Ends the server process and lets go of its pipe.
     */
    private function stop(): void
    {
        if(is_null($this->process)) return;

        $this->pipe->closeStdin();
        $this->waitFor(fn() => ! $this->pipe->stdoutOpen() || ! $this->running());

        app(Loop::class)->forget($this->watcher);

        foreach([SIGTERM, SIGKILL] as $signal)
        {
            if(! $this->running()) break;

            proc_terminate($this->process, $signal);
            $this->waitFor(fn() => ! $this->running());
        }

        proc_close($this->process);

        [$this->process, $this->pipe, $this->lines] = [null, null, []];
    }

    private function running(): bool
    {
        return proc_get_status($this->process)['running'];
    }

    /**
     * Runs the loop until $done, or EXIT_GRACE seconds. A process exiting wakes nothing, so a timer has the loop look.
     */
    private function waitFor(Closure $done): void
    {
        $loop = app(Loop::class);
        $expired = false;

        $deadline = $loop->at(self::EXIT_GRACE, function() use (&$expired) {
            $expired = true;
        });
        $look = $loop->every(0.02, fn() => null, $this->watcher.'.exit');

        try {
            $loop->until(function() use (&$expired, $done) { return $expired || $done(); });
        } finally {
            $deadline->cancel();
            $look->cancel();
        }
    }
}
