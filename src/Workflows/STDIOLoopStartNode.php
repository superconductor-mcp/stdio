<?php

namespace SuperconductorMCP\STDIO\Workflows;

use ReflectionException;
use Voyager\Workflows\Node;
use Voyager\Workflows\SharedBag;
use Voyager\Contracts\IOPools\Loop;
use SuperconductorMCP\STDIO\STDIOBag;
use Fabricate\Mechanism\ProcedureRouter;
use SuperconductorMCP\STDIO\Pipes\StdioPipe;
use SuperconductorMCP\STDIO\Events\PipeClosed;
use Fabricate\NutsAndBolts\Messages\MCPRequest;
use Fabricate\NutsAndBolts\ProgressReporter;
use Fabricate\NutsAndBolts\RequestMeta;
use Fabricate\NutsAndBolts\Messages\MCPResponse;
use Fabricate\NutsAndBolts\Messages\MCPNotification;
use Fabricate\Mechanism\Workflows\LogIncomingMessage;
use SuperconductorMCP\STDIO\Events\HandshakeTimedOut;
use SuperconductorMCP\STDIO\Events\PingDue;
use SuperconductorMCP\STDIO\Events\ClientUnresponsive;
use Fabricate\NutsAndBolts\Messages\Ping\PingRequest;
use Fabricate\NutsAndBolts\Messages\Responses\ReceivedResponse;
use Fabricate\NutsAndBolts\Messages\Errors\InvalidRequest;
use Fabricate\NutsAndBolts\Messages\Errors\JsonRpcError;
use Fabricate\NutsAndBolts\Messages\Errors\InternalError;
use Throwable;
use SuperconductorMCP\STDIO\StateMachines\Lifecycle\IdleState;
use Fabricate\NutsAndBolts\Messages\Initialize\InitializeResponse;
use SuperconductorMCP\STDIO\StateMachines\Lifecycle\HandshakeState;

class STDIOLoopStartNode extends Node
{
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
        return $shared;
    }

    /**
     * @throws ReflectionException
     */
    public function exec(mixed $prepRes): mixed
    {
        /** @var STDIOBag $prepRes */
        foreach($this->mail as $message)
        {
            if($message instanceof MCPRequest)
            {
                $this->processRequest($message, $prepRes);
            }
            elseif($message instanceof MCPNotification)
            {
                $this->processNotification($message, $prepRes);
            }
            elseif($message instanceof ReceivedResponse)
            {
                $this->processResponse($message, $prepRes);
            }
            elseif($message instanceof PingDue)
            {
                if(! is_null($result = $this->sendPing($prepRes)))
                {
                    return $result;
                }
            }
            else
            {
                $result = $this->processDefault($message);
                if($result instanceof PipeClosed || $result instanceof HandshakeTimedOut)
                {
                    return $result;
                }
            }
        }

        return null;
    }

    public function post(SharedBag $shared, mixed $prepRes, mixed $execRes): ?string
    {
        return match(true)
        {
            $execRes instanceof PipeClosed => 'pipe-closed',
            $execRes instanceof HandshakeTimedOut => 'handshake-timed-out',
            $execRes instanceof ClientUnresponsive => 'client-unresponsive',
            default => null,
        };
    }

    protected function processRequest(MCPRequest $request, STDIOBag $bag): void
    {
        if(! $bag->their_ids->receive($request->id))
        {
            // the client already used this id: refuse it without touching state or routing
            $error_message = new InvalidRequest($request->id, "Request id [{$request->id}] has already been used.");
            $this->pipe->send($error_message->toJson());
        }
        else
        {
            new LogIncomingMessage($request)->dispatch($bag);

            if($bag->state->canProcess($request))
            {
                $bag->in_flight[$request->id] = true;

                // whatever handles this request can report progress through the container's ProgressReporter;
                // a fiber runs its first stretch at once, so the reporter is registered before the handler is built
                $run = function () use ($request, $bag): MCPResponse {
                    $progress = $this->progressReporter($request);
                    app()->registerInstance(ProgressReporter::class, $progress);

                    try {
                        return $this->router->dispatch($request, $bag);
                    } finally {
                        $progress->finish();
                        app()->registerInstance(ProgressReporter::class, ProgressReporter::silent());
                    }
                };

                if($this->router->runsAsync($request))
                {
                    // the procedure yields whenever it waits on the loop; stdin keeps being read in the meantime
                    $this->event_loop->async($run)->then(
                        fn (MCPResponse $response) => $this->respond($request, $response, $bag),
                        fn (Throwable $e) => $this->respond($request, new InternalError($request->id, $e->getMessage()), $bag),
                    );
                }
                else
                {
                    $this->respond($request, $run(), $bag);
                }
            }
            else
            {
                // the lifecycle state refused this request: answer it with an error instead
                $error_message = new InvalidRequest($request->id, "Method [{$request->method}] is not allowed.");
                $this->pipe->send($error_message->toJson());
            }
        }
    }

    // the request finished: its response goes out unless the client cancelled it meanwhile
    protected function respond(MCPRequest $request, MCPResponse $response, STDIOBag $bag): void
    {
        unset($bag->in_flight[$request->id]);

        if(isset($bag->cancelled[$request->id]))
        {
            unset($bag->cancelled[$request->id]);
            return;
        }

        $this->pipe->send($response->toJson());
        $this->updateState($response, $bag);

        // anything the server queued while handling this request goes out after its response
        static::flushOutbox($bag, $this->pipe);
    }

    protected function processNotification(MCPNotification $notification, STDIOBag $bag): void
    {
        new LogIncomingMessage($notification)->dispatch($bag);
        if(isset($bag->notifications[$notification->method]))
        {
            $state_before = $bag->state;
            $action = new ($bag->notifications[$notification->method])();
            $action->handle($bag, $notification);

            // an action that moved the lifecycle (e.g. initialized → Available) goes through the same timer reset as any other change
            if($bag->state !== $state_before)
            {
                $this->stateChanged($bag);
            }
        }
    }

    // responses never go through the ledger: their id is one this side already recorded when it sent the request
    protected function progressReporter(MCPRequest $request): ProgressReporter
    {
        $token = RequestMeta::progressToken($request);

        return is_null($token)
            ? ProgressReporter::silent()
            : new ProgressReporter($token, fn ($notification) => $this->pipe->send($notification->toJson()), (float) config('mcp.progress.min_interval', 0.1));
    }

    // sends what the server queued, filtered by what this session's client wants; nothing reaches a client before the handshake
    public static function flushOutbox(STDIOBag $bag, StdioPipe $pipe): void
    {
        $notifications = $bag->server_configuration?->drainNotifications() ?? [];

        if($bag->state instanceof IdleState)
        {
            return;
        }

        foreach($notifications as $notification)
        {
            if($bag->wants($notification))
            {
                $pipe->send($notification->toJson());
            }
        }
    }

    protected function processResponse(ReceivedResponse $response, STDIOBag $bag): void
    {
        if(! is_int($response->id) || ! isset($bag->pending_pings[$response->id]))
        {
            // nobody is waiting for this id: ignored, as the cancellation rules expect
            return;
        }

        // any answer proves the client is alive, including a late one to an older ping
        $bag->pending_pings = [];
        $bag->ping_misses = 0;
    }

    protected function sendPing(STDIOBag $bag): ?ClientUnresponsive
    {
        if($bag->pending_pings !== [])
        {
            $bag->ping_misses++;
            app('log')->warning('mcp.stdio ping unanswered', [
                'misses' => $bag->ping_misses,
                'max_misses' => (int) config('stdio.ping_time.max_misses'),
            ]);

            if($bag->ping_misses >= (int) config('stdio.ping_time.max_misses'))
            {
                app('log')->error('mcp.stdio client unresponsive, shutting down', ['misses' => $bag->ping_misses]);

                return new ClientUnresponsive();
            }
        }

        $ping = new PingRequest($bag->own_ids->next());
        $bag->pending_pings[$ping->id] = true;
        $this->pipe->send($ping->toJson());

        return null;
    }

    protected function processDefault(mixed $message): HandshakeTimedOut|PipeClosed|null
    {
        if($message instanceof PipeClosed || $message instanceof HandshakeTimedOut)
        {
            return $message;
        }
        elseif($message instanceof JsonRpcError)
        {
            // the decoder already built the right error with the right id; a notification never gets one back
            if($message->replies())
            {
                $this->pipe->send($message->toJson());
            }

            return null;
        }
        else
        {
            // anything not handled above is mail the server cannot act on
            $error_message = new InvalidRequest(null, 'The server could not handle this message.');
            $this->pipe->send($error_message->toJson());
            return null;
        }
    }

    private function updateState(MCPResponse $response, STDIOBag &$bag): void
    {
        if($response instanceof InitializeResponse)
        {
            if($bag->state instanceof IdleState)
            {
                $bag->put('state', $bag->state->nextState());
            }

            $this->stateChanged($bag);
        }
    }

    private function stateChanged(STDIOBag $bag): void
    {
        $bag->stop_waiting?->cancel();
        $bag->stop_waiting = null;

        if($bag->state instanceof HandshakeState && config('stdio.handshake.stop_waiting.enabled'))
        {
            $bag->stop_waiting = $this->event_loop->at(
                (float) config('stdio.handshake.stop_waiting.ttl'),
                fn () => $this->event_loop->post(new HandshakeTimedOut()),
            );
        }

        // every state change restarts the ping interval; Idle has no client, so it never pings
        $bag->ping_timer?->cancel();
        $bag->ping_timer = null;

        if($bag->state instanceof IdleState)
        {
            $bag->pending_pings = [];
            $bag->ping_misses = 0;
        }
        elseif(config('stdio.ping_time.enabled'))
        {
            $bag->ping_timer = $this->event_loop->every(
                (float) config('stdio.ping_time.ttl'),
                fn () => $this->event_loop->post(new PingDue()),
                'mcp.stdio.ping',
            );
        }
    }
}