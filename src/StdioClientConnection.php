<?php

namespace SuperconductorMCP\STDIO;

use Closure;
use Fabricate\Client\ClientConfiguration;
use Fabricate\Client\MCPClient;
use Fabricate\Client\Signals\ConnectionFailed;
use Fabricate\Contracts\Client\ClientConnection;
use Fabricate\Contracts\Mechanism\DecodedMessage;
use Fabricate\Contracts\Schema\Messaging\Initialize\InitializeResult;
use Fabricate\Contracts\Schema\Messaging\MCPError;
use Fabricate\Contracts\Schema\Messaging\RPC\JSONRPCMessage;
use Fabricate\Contracts\Schema\ProtocolVersion;
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
use SuperconductorMCP\STDIO\Pipes\StdioClientPipe;
use Voyager\Contracts\IOPools\Loop;
use Voyager\NutsAndBolts\Str;

/**
 * A client's connection to a server it launches as a subprocess, talking over its stdin and stdout. Opening it runs
 * the handshake here, never in the entity: a client that supports 2026-07-28 probes with server/discover first, as
 * the stdio transport asks, and a server that answers it is modern. A recognized modern error ends the attempt; any
 * other error, or silence until mcp.clients.initialize_timeout, means a legacy server, which gets initialize in the
 * newest version the client has a handshake for, then notifications/initialized. Pings the server sends meanwhile
 * are answered, which the lifecycle allows before initialized; whatever else it sends waits in the inbox and reaches
 * the client first thing. The MCPClient is built in the agreed version only after that.
 *
 * Once the client exists, every line read goes to it, and what it queues goes back out. Async mail the client's work
 * posts to the loop reaches the session through deliver(), from whoever handles the loop's mail.
 *
 * A legacy server that exits ends the session: every request in flight gets the reason as its error, ConnectionFailed
 * fires, and failure() says why. A 2026-07-28 server that exits unexpectedly is launched again, as the stdio transport
 * asks, keeping the client; the requests it was answering are answered with an error saying so, since the new process
 * never saw them. A relaunched server that exits before this connection reads a line from it, or that cannot be
 * started, cannot be talked to either, so the session ends the same way instead of launching it again.
 */
final class StdioClientConnection implements ClientConnection
{
    /** Seconds the server gets to exit after each step of shutdown, before the next. */
    private const float EXIT_GRACE = 2.0;

    /** @var resource|null */
    private mixed $process = null;

    private ?StdioClientPipe $pipe = null;

    private readonly string $watcher;

    /** Whether the running process was launched after an earlier one exited. */
    private bool $relaunched = false;

    /** Whether a line has been read from the running process. */
    private bool $heard = false;

    private ?MCPClient $client = null;

    private ?string $failure = null;

    private bool $closing = false;

    /** The protocol version the server and client agreed on. */
    public private(set) ?ProtocolVersion $version = null;

    /** What the server said about itself in the handshake: its InitializeResult, or on 2026-07-28 its DiscoverResult. */
    public private(set) InitializeResult|V20260728\Messaging\Discover\DiscoverResult|null $server = null;

    /** @var list<DecodedMessage|DecodedBatch> What the server sent before the client existed. */
    private array $inbox = [];

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
        if(! $this->launch()) return;

        if($version = $this->negotiate()) $this->start($version);
        else $this->close();
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
     * Ends the connection: a request still in flight is answered with an error saying so. The server is shut down as
     * the stdio transport asks: its stdin closes, then it gets EXIT_GRACE seconds to exit, then SIGTERM and the same
     * again, then SIGKILL.
     */
    public function close(): void
    {
        $this->closing = true;
        $this->client?->close();
        $this->client = null;

        $this->stop();
    }

    /**
     * Starts the server process, or says why it could not in failure().
     */
    private function launch(): bool
    {
        // A command that cannot start is a failure of this connection, not a warning for the process.
        set_error_handler(fn() => true);

        try {
            $process = proc_open(
                [$this->command, ...$this->args],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
                null,
                $this->env === [] ? null : [...getenv(), ...$this->env],
            );
        } finally {
            restore_error_handler();
        }

        if(! is_resource($process))
        {
            $this->failure = "Could not start [{$this->command}].";
            return false;
        }

        [$this->process, $this->failure, $this->heard] = [$process, null, false];

        $this->pipe = new StdioClientPipe(
            ...$pipes,
            on_line: function(string $line) {
                $this->heard = true;
                $this->received(MessageDecoder::fromWire($line));
            },
            on_end: $this->ended(...),
            before_wait: $this->sendQueued(...),
        );

        app(Loop::class)->resource($this->watcher, $this->pipe);

        return true;
    }

    /**
     * The handshake is done: the session starts in the agreed version, with the client built in it, and what the
     * server sent meanwhile reaches it.
     */
    private function start(ProtocolVersion $version): void
    {
        $this->version = $version;
        $this->client = new MCPClient($this->configuration, $version->value);

        foreach(array_splice($this->inbox, 0) as $message) $this->receive($message);
    }

    /**
     * The version the server and client agree on, or null with the reason in failure().
     */
    private function negotiate(): ?ProtocolVersion
    {
        if(in_array(ProtocolVersion::V20260728->value, $this->configuration->supportedProtocolVersions(), true))
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
    private function discover(): ?ProtocolVersion
    {
        $modern = ProtocolVersion::V20260728;
        $id = new RequestId((string) Str::uuid());
        $meta = new V20260728\Messaging\MetaObjects\RequestMetaObject($modern->value, $this->configuration->capabilities($modern->value), $this->configuration->implementation($modern->value));

        $this->send(new V20260728\Messaging\Discover\DiscoverRequest($id, new V20260728\Messaging\Params\RequestParams($meta)));

        $answer = $this->awaitAnswer($id, $modern);

        if($answer instanceof DecodedResponse)
        {
            $result = MessageHydrator::hydrateAs($answer, $modern->value, 'server/discover');

            if(! $result instanceof V20260728\Messaging\Discover\DiscoverResult)
            {
                $this->failure = 'The server answered server/discover with a malformed result'.($result instanceof MCPError ? ': '.$result->toArray()['message'] : '.');
                return null;
            }

            if(! in_array($modern->value, $result->supportedVersions, true))
            {
                $this->failure = 'The server supports ['.implode(', ', $result->supportedVersions).'], and this client none of them.';
                return null;
            }

            $this->server = $result;

            return $modern;
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
    private function initialize(): ?ProtocolVersion
    {
        $id = new RequestId((string) Str::uuid());
        $requested = ProtocolVersion::from($this->configuration->handshakeProtocolVersion());

        $this->send(Envelope::request($requested->value, $this->configuration->initializeRequest($id, $requested->value), $id));

        $answer = $this->awaitAnswer($id, $requested);

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

        // Which version's result this is can only be read off the wire: hydration needs the version first.
        $offered = $answer->result['protocolVersion'] ?? null;
        $version = is_string($offered) ? ProtocolVersion::tryFrom($offered) : null;

        if(is_null($version) || ! in_array($version->value, $this->configuration->handshakeProtocolVersions(), true))
        {
            $this->failure = 'The server answered initialize in protocol version ['.(is_string($offered) ? $offered : 'none').'], which this client does not support.';
            return null;
        }

        $result = MessageHydrator::hydrateAs($answer, $version->value, 'initialize');

        if($result instanceof MCPError)
        {
            $this->failure = 'The server answered initialize with a malformed result: '.$result->toArray()['message'];
            return null;
        }

        $this->server = $result;

        $this->send(Envelope::notification($version->value, match($version) {
            ProtocolVersion::V20241105 => new V20241105\Messaging\Initialize\InitializedNotification(),
            ProtocolVersion::V20250326 => new V20250326\Messaging\Initialize\InitializedNotification(),
            ProtocolVersion::V20250618 => new V20250618\Messaging\Initialize\InitializedNotification(),
            ProtocolVersion::V20251125 => new V20251125\Messaging\Initialize\InitializedNotification(),
        }));

        return $version;
    }

    /**
     * Waits for the response to a handshake request, until it comes, the server ends, or
     * mcp.clients.initialize_timeout. Pings the server sends meanwhile are answered; everything else stays in the
     * inbox for the client.
     */
    private function awaitAnswer(RequestId $id, ProtocolVersion $version): DecodedResponse|DecodedError|null
    {
        $answer = null;

        $this->wait(function() use ($id, $version, &$answer) {
            foreach($this->inbox as $index => $message)
            {
                if(($message instanceof DecodedResponse || $message instanceof DecodedError) && $message->id()?->id === $id->id)
                {
                    $answer = $message;
                    unset($this->inbox[$index]);
                    break;
                }

                if($message instanceof DecodedRequest && $message->method === 'ping')
                {
                    $this->send(Envelope::response($version->value, $message->id(), new EmptyResult()));
                    unset($this->inbox[$index]);
                }
            }

            $this->inbox = array_values($this->inbox);

            return ! is_null($answer) || ! is_null($this->failure);
        }, (float) config('mcp.clients.initialize_timeout'));

        return $answer;
    }

    private function send(JSONRPCMessage $message): void
    {
        $this->pipe->send($message->toJson());
    }

    /**
     * One line from the server: the client's once it exists, otherwise kept until it does.
     */
    private function received(DecodedMessage|DecodedBatch $message): void
    {
        $this->client ? $this->receive($message) : $this->inbox[] = $message;
    }

    /**
     * One message from the server, once the client exists: it goes to the client, and an answer goes back. On
     * 2026-07-28 a stdio client must not write responses, so a line that could not be read gets none.
     */
    private function receive(DecodedMessage|DecodedBatch $message): void
    {
        if($message instanceof InvalidMessage)
        {
            if($this->version !== ProtocolVersion::V20260728) $this->send($this->refusal($message));
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
        if($this->version !== ProtocolVersion::V20250326)
        {
            if($this->version !== ProtocolVersion::V20260728) $this->send($this->refusal(new InvalidMessage(null, RPCErrorCode::INVALID_REQUEST, "Batches are not part of {$this->version->value}.")));
            return;
        }

        $answers = [];

        foreach($batch->messages as $message)
        {
            $answer = $message instanceof InvalidMessage
                ? $this->refusal($message)
                : $this->client->receiveMessage($message);

            if($answer) $answers[] = $answer;
        }

        if($answers === []) return;

        $this->sendQueued();
        $this->send(new V20250326\Messaging\RPC\JSONRPCBatchResponse($answers));
    }

    /**
     * The session version's JSON-RPC error answering a line that could not be read, under its id when it had one.
     */
    private function refusal(InvalidMessage $message): JSONRPCMessage
    {
        [$id, $code, $reason] = [$message->id(), $message->code->value, $message->message];

        return match($this->version) {
            ProtocolVersion::V20241105 => new V20241105\Messaging\RPC\JSONRPCError('2.0', $id, $code, $reason),
            ProtocolVersion::V20250326 => new V20250326\Messaging\RPC\JSONRPCError('2.0', $id, $code, $reason),
            ProtocolVersion::V20250618 => new V20250618\Messaging\RPC\JSONRPCError('2.0', $id, $code, $reason),
            ProtocolVersion::V20251125 => new V20251125\Messaging\RPC\JSONRPCErrorResponse('2.0', $id, new V20251125\Messaging\MCPError($code, $reason)),
        };
    }

    /**
     * Sends everything the client queued, oldest first.
     */
    private function sendQueued(): void
    {
        foreach($this->client?->pullQueued() ?? [] as $message) $this->send($message);
    }

    /**
     * The server can no longer be talked to. A 2026-07-28 session launches it again, unless the process that exited
     * was itself a relaunch nothing was read from; any other session is over.
     */
    private function ended(string $reason): void
    {
        if($this->closing || ! is_null($this->failure)) return;

        $this->failure = $reason;

        if(is_null($client = $this->client)) return;

        if($client->active_protocol_version !== ProtocolVersion::V20260728->value || ($this->relaunched && ! $this->heard))
        {
            $this->lost($client, $this->relaunched && ! $this->heard ? "{$reason} It was launched again and exited before saying anything, so it is not launched a third time." : $reason);
            return;
        }

        $client->failPending("{$reason} The server was launched again, and never saw the request.");

        // not from inside the pipe's own wake: the loop is still walking it
        app(Loop::class)->at(0, function() use ($client) {
            if($this->closing) return;

            $this->stop();
            $this->relaunched = true;

            if(! $this->launch()) $this->lost($client, $this->failure);
        });
    }

    /**
     * The session is over: the client is gone, every request it had in flight gets the reason as its error,
     * failure() keeps it, and the application hears ConnectionFailed.
     */
    private function lost(MCPClient $client, string $reason): void
    {
        [$this->client, $this->failure] = [null, $reason];
        $client->close($reason);

        signal(new ConnectionFailed($client->session_id, $reason));
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

        [$this->process, $this->pipe, $this->inbox] = [null, null, []];
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
        $look = app(Loop::class)->every(0.02, fn() => null, $this->watcher.'.exit');

        try {
            $this->wait($done, self::EXIT_GRACE);
        } finally {
            $look->cancel();
        }
    }

    /**
     * Runs the loop until $done, or $seconds.
     */
    private function wait(Closure $done, float $seconds): void
    {
        $loop = app(Loop::class);
        $expired = false;

        // a quiet loop never re-checks the condition, so the timer is what wakes it
        $deadline = $loop->at($seconds, function() use (&$expired) {
            $expired = true;
        });

        try {
            $loop->until(function() use (&$expired, $done) { return $expired || $done(); });
        } finally {
            $deadline->cancel();
        }
    }
}
