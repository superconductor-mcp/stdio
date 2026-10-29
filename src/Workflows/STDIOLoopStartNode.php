<?php

namespace SuperconductorMCP\STDIO\Workflows;

use Voyager\Workflows\Node;
use Voyager\Workflows\SharedBag;
use Voyager\Contracts\IOPools\Loop;
use SuperconductorMCP\STDIO\STDIOBag;
use Fabricate\Mechanism\ProcedureRouter;
use Fabricate\Mechanism\Session\Session;
use SuperconductorMCP\STDIO\Pipes\StdioPipe;
use SuperconductorMCP\STDIO\Events\PingDue;
use SuperconductorMCP\STDIO\Events\PipeClosed;
use SuperconductorMCP\STDIO\Events\BatchReceived;
use SuperconductorMCP\STDIO\Events\HandshakeTimedOut;
use SuperconductorMCP\STDIO\Events\ClientUnresponsive;
use Fabricate\NutsAndBolts\Messages\Ping\PingRequest;
use Fabricate\NutsAndBolts\Messages\Errors\InvalidRequest;
use Fabricate\NutsAndBolts\Messages\Responses\ReceivedResponse;
use Fabricate\Mechanism\Session\Lifecycle\IdleState;
use Fabricate\Mechanism\Session\Lifecycle\HandshakeState;

// one frame of the stdio server: the session logic lives in superconductor/core, this node adds the pipe, the pings and the timers
class STDIOLoopStartNode extends Node
{
    private Session $session;

    public function __construct(
        protected array $mail,
        protected StdioPipe &$pipe,
        protected Loop $event_loop,
        protected ProcedureRouter $router = new ProcedureRouter()
    ) {
        parent::__construct();
    }

    public function prep(SharedBag $shared): mixed
    {
        /** @var STDIOBag $shared */
        $this->session = new Session($shared, $this->event_loop, $this->router);
        $this->session->onStateChange(fn (STDIOBag $bag) => $this->stateChanged($bag));

        return $shared;
    }

    public function exec(mixed $prepRes): mixed
    {
        /** @var STDIOBag $prepRes */
        $send = fn (string $line) => $this->pipe->send($line);

        foreach ($this->expand($this->mail, $prepRes, $send) as $message) {
            if ($message instanceof PipeClosed || $message instanceof HandshakeTimedOut) {
                return $message;
            } elseif ($message instanceof PingDue) {
                if (! is_null($result = $this->sendPing($prepRes))) {
                    return $result;
                }
            } elseif ($message instanceof ReceivedResponse) {
                $this->processResponse($message, $prepRes);
            } elseif (! $this->session->handle($message, $send)) {
                // anything not handled above is mail the server cannot act on
                $this->pipe->send((new InvalidRequest(null, 'The server could not handle this message.'))->toJson());
            }
        }

        return null;
    }

    public function post(SharedBag $shared, mixed $prepRes, mixed $execRes): ?string
    {
        return match (true) {
            $execRes instanceof PipeClosed => 'pipe-closed',
            $execRes instanceof HandshakeTimedOut => 'handshake-timed-out',
            $execRes instanceof ClientUnresponsive => 'client-unresponsive',
            default => null,
        };
    }

    // notifications queued outside any request (timers, sensors, background work) go out on the next frame
    public static function flushOutbox(STDIOBag $bag, StdioPipe $pipe, Loop $loop): void
    {
        (new Session($bag, $loop))->flushOutbox(fn (string $line) => $pipe->send($line));
    }

    // a batch is opened up for a session that takes batches, and refused whole for one that negotiated 2025-06-18
    private function expand(array $mail, STDIOBag $bag, \Closure $send): array
    {
        $messages = [];

        foreach ($mail as $message) {
            if (! $message instanceof BatchReceived) {
                $messages[] = $message;
            } elseif ($bag->takesBatches()) {
                array_push($messages, ...$message->messages);
            } else {
                $send((new InvalidRequest(null, "Batches are not supported in protocol {$bag->protocol_version}."))->toJson());
            }
        }

        return $messages;
    }

    // responses never go through the ledger: their id is one this side already recorded when it sent the request
    protected function processResponse(ReceivedResponse $response, STDIOBag $bag): void
    {
        if (! is_int($response->id) || ! isset($bag->pending_pings[$response->id])) {
            // not a ping: an answer to something the session asked (elicitation), or to nothing at all
            $this->session->handle($response, fn (string $line) => $this->pipe->send($line));

            return;
        }

        // any answer proves the client is alive, including a late one to an older ping
        $bag->pending_pings = [];
        $bag->ping_misses = 0;
    }

    protected function sendPing(STDIOBag $bag): ?ClientUnresponsive
    {
        if ($bag->pending_pings !== []) {
            $bag->ping_misses++;
            app('log')->warning('mcp.stdio ping unanswered', [
                'misses' => $bag->ping_misses,
                'max_misses' => (int) config('stdio.ping_time.max_misses'),
            ]);

            if ($bag->ping_misses >= (int) config('stdio.ping_time.max_misses')) {
                app('log')->error('mcp.stdio client unresponsive, shutting down', ['misses' => $bag->ping_misses]);

                return new ClientUnresponsive();
            }
        }

        $ping = new PingRequest($bag->own_ids->next());
        $bag->pending_pings[$ping->id] = true;
        $this->pipe->send($ping->toJson());

        return null;
    }

    private function stateChanged(STDIOBag $bag): void
    {
        $bag->stop_waiting?->cancel();
        $bag->stop_waiting = null;

        if ($bag->state instanceof HandshakeState && config('stdio.handshake.stop_waiting.enabled')) {
            $bag->stop_waiting = $this->event_loop->at(
                (float) config('stdio.handshake.stop_waiting.ttl'),
                fn () => $this->event_loop->post(new HandshakeTimedOut()),
            );
        }

        // every state change restarts the ping interval; Idle has no client, so it never pings
        $bag->ping_timer?->cancel();
        $bag->ping_timer = null;

        if ($bag->state instanceof IdleState) {
            $bag->pending_pings = [];
            $bag->ping_misses = 0;
        } elseif (config('stdio.ping_time.enabled')) {
            $bag->ping_timer = $this->event_loop->every(
                (float) config('stdio.ping_time.ttl'),
                fn () => $this->event_loop->post(new PingDue()),
                'mcp.stdio.ping',
            );
        }
    }
}
