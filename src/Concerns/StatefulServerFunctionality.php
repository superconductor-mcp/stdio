<?php

namespace SuperconductorMCP\STDIO\Concerns;

use JsonException;
use ReflectionException;
use Fabricate\Schema\V20241105;
use Fabricate\Schema\V20250326;
use Fabricate\Schema\V20250618;
use Fabricate\Schema\V20251125;
use Fabricate\Server\MCPServer;
use Fabricate\Mechanism\Mail\OutboundMessage;
use Fabricate\Mechanism\MessageHydrator;
use Fabricate\Contracts\Schema\RequestId;
use Fabricate\Contracts\Schema\RPCErrorCode;
use Fabricate\Mechanism\Decoding\DecodedError;
use SuperconductorMCP\STDIO\Events\PipeClosed;
use SuperconductorMCP\STDIO\Events\BatchReceived;
use Fabricate\Mechanism\Decoding\DecodedBatch;
use Fabricate\Mechanism\Decoding\InvalidMessage;
use Fabricate\Contracts\Schema\Messaging\Result;
use Fabricate\Mechanism\Decoding\DecodedRequest;
use Fabricate\Contracts\Mechanism\DecodedMessage;
use Fabricate\Mechanism\Decoding\DecodedResponse;
use Fabricate\Contracts\Schema\Messaging\Request;
use Fabricate\Contracts\Schema\Messaging\MCPError;
use Fabricate\Contracts\Schema\Messaging\Notification;
use Fabricate\Contracts\Core\SuperconductorMCPException;
use Fabricate\Contracts\Schema\Messaging\Ping\PingRequest;
use Fabricate\Schema\Common\Messaging\Result as SchemaResult;
use Fabricate\Contracts\Schema\Messaging\Logging\SetLevelRequest;
use Fabricate\Schema\V20260728\Messaging\RPC\JSONRPCErrorResponse;
use Fabricate\Core\Routing\ProcedureRouterFacade as ProcedureRouter;
use Fabricate\Schema\V20260728\Messaging\MCPError as HydrationError;
use Fabricate\Contracts\Schema\Messaging\Initialize\InitializeRequest;
use Fabricate\Contracts\Schema\Messaging\Initialize\InitializedNotification;

trait StatefulServerFunctionality
{
    use PipeManagement, ServerConfigurations;

    private ?MCPServer $server = null;

    private bool $should_continue = true;

    private ?string $negotiated_protocol_version = null;

    /** @var array<string|int, string> The method of each request this server sent and still awaits an answer to, by id. */
    private array $pending_requests = [];

    protected function shouldContinue(): bool
    {
        return $this->should_continue;
    }

    /**
     * @throws ReflectionException
     * @throws JsonException
     */
    protected function work(array $mail = []): void
    {
        $this->processMail($mail);
        $this->fireOutgoingMessages();
    }

    /**
     * Sends everything the server queued, oldest first: replies from this turn, and work that finished since the last one.
     */
    protected function fireOutgoingMessages(): void
    {
        foreach($this->server?->pullQueued() ?? [] as $message)
        {
            $this->pipe->send($message->toJson());
        }
    }

    /**
     * @throws ReflectionException
     * @throws JsonException
     */
    protected function processMail(array $mail): void
    {
        $this->should_continue = true;

        if(!empty($mail))
        {
            if($this->sessionHasStarted($mail))
            {
                foreach ($mail as $message)
                {
                    if($message instanceof PipeClosed)
                    {
                        signal('pipe-closed');
                        return;
                    }

                    if($message instanceof BatchReceived)
                    {
                        $this->answerBatch($message->messages);
                    }
                    elseif($message instanceof OutboundMessage)
                    {
                        // Async work finishing, or a notification: it belongs to the session it names.
                        if($message->session_id === $this->server->session_id) $this->server->queue($message->message());
                    }
                    elseif($message instanceof DecodedMessage && ($reply = $this->server->receiveMessage($message)))
                    {
                        $this->server->queue($reply);
                    }

                    /*
                    $decoded = $message;
                    $message = $this->decodeMessage($decoded);
                    if($message instanceof HydrationError && $decoded instanceof DecodedRequest)
                    {
                        $this->pipe->send(new JSONRPCErrorResponse('2.0', $decoded->id(), $message)->toJson());
                    }

                    if($decoded instanceof DecodedRequest)
                    {
                        if($message instanceof Request)
                        {
                            if($message instanceof InitializeRequest)
                            {
                                $this->pipe->send(new JSONRPCErrorResponse('2.0', $decoded->id(), new HydrationError(
                                    RPCErrorCode::INVALID_REQUEST->value,
                                    'Session already initialized: [initialize] can only be sent once.',
                                ))->toJson());
                            }
                            else
                            {
                                $request = MessageHydrator::hydrateAs($decoded, $this->negotiated_protocol_version, $message->method);
                                try {
                                    $response = $request instanceof MCPError
                                        ? $request
                                        : ProcedureRouter::dispatch($this->negotiated_protocol_version, $request, $this->server_configuration);
                                } catch (SuperconductorMCPException $e) {
                                    // The router refuses by throwing; its code and message are the JSON-RPC error.
                                    $response = new HydrationError($e->getCode(), $e->getMessage());
                                }

                                $this->respond($decoded->id(), $response);
                            }
                        }
                        else
                        {
                            $this->pipe->send(new JSONRPCErrorResponse('2.0', $decoded->id(), new HydrationError(
                                RPCErrorCode::INVALID_REQUEST->value,
                                "Unsupported request: [{$decoded->method}].",
                            ))->toJson());
                        }
                    }
                    elseif($message instanceof Notification)
                    {
                        // @todo - ignore notifications till its time to know what to do with them
                    }
                    elseif($message instanceof Result)
                    {
                        // @todo - ignore notifications till its time to know what to do with them
                    }
                    */
                }

            }
        }
    }

    /**
     * A batch, which only 2025-03-26 sessions accept: the answers to its requests go out as one batch, after whatever
     * was already queued; answers that come later, from async work, follow on their own. Other versions refuse it.
     */
    private function answerBatch(DecodedBatch $batch): void
    {
        if($this->server->active_protocol_version !== '2025-03-26')
        {
            $this->pipe->send(new InvalidMessage(null, RPCErrorCode::INVALID_REQUEST, 'Batches are only accepted once a 2025-03-26 session has started.')->toJson());
            return;
        }

        $answers = [];

        foreach($batch->messages as $message)
        {
            $answer = $message instanceof InvalidMessage
                ? new V20250326\Messaging\RPC\JSONRPCError('2.0', $message->id(), $message->code->value, $message->message)
                : $this->server->receiveMessage($message);

            if($answer) $answers[] = $answer;
        }

        if($answers === []) return;

        $this->fireOutgoingMessages();
        $this->pipe->send(new V20250326\Messaging\RPC\JSONRPCBatchResponse($answers)->toJson());
    }

    /**
     * @throws ReflectionException
     */
    protected function setupEmergencyStop(): void
    {
        $should_continue = &$this->should_continue;
        app('signals')->listen('pipe-closed', function () use (&$should_continue) {
            $should_continue = false;
        });
    }

    /**
     * @param array $mail
     * @return bool
     * @throws ReflectionException|JsonException
     */
    private function sessionHasStarted(array $mail): bool
    {
        $results = true;

        if(is_null($this->server))
        {
            $this->processInitialization($mail);
            $results = false;
        }

        return $results;

    }

    /**
     * @param array $mail
     * @return void
     * @throws ReflectionException|JsonException
     */
    private function processInitialization(array $mail): void
    {
        foreach(array_values($mail) as $at => $message)
        {
            if($message instanceof PipeClosed)
            {
                signal('pipe-closed');
                return;
            }

            if($message instanceof BatchReceived)
            {
                // initialize can't be batched, so nothing in a batch can start a session.
                $this->pipe->send(new InvalidMessage(null, RPCErrorCode::INVALID_REQUEST, 'Batches are only accepted once a 2025-03-26 session has started.')->toJson());
            }
            elseif($message instanceof DecodedMessage)
            {
                $decoded = $message;
                $message = $this->decodeMessage($decoded);

                if($message instanceof HydrationError && $decoded instanceof DecodedRequest)
                {
                    $this->pipe->send(new JSONRPCErrorResponse('2.0', $decoded->id(), $message)->toJson());
                }

                if($message instanceof InitializeRequest)
                {
                    if($this->isUnsupportedVersion($message->protocolVersion()))
                    {
                        // The error the MCP lifecycle spec gives for a version the server cannot speak.
                        $this->pipe->send(new JSONRPCErrorResponse('2.0', $decoded->id(), new HydrationError(
                            RPCErrorCode::INVALID_PARAMS->value,
                            'Unsupported protocol version',
                            ['supported' => $this->handshakeVersions(), 'requested' => $message->protocolVersion()],
                        ))->toJson());
                    }
                    else
                    {
                        $result = $this->server_configuration->initializeResult($message->protocolVersion());

                        $response = match (true) {
                            $result instanceof V20241105\Messaging\Initialize\InitializeResult => new V20241105\Messaging\RPC\JSONRPCResponse('2.0', $decoded->id(), $result),
                            $result instanceof V20250326\Messaging\Initialize\InitializeResult => new V20250326\Messaging\RPC\JSONRPCResponse('2.0', $decoded->id(), $result),
                            $result instanceof V20250618\Messaging\Initialize\InitializeResult => new V20250618\Messaging\RPC\JSONRPCResponse('2.0', $decoded->id(), $result),
                            $result instanceof V20251125\Messaging\Initialize\InitializeResult => new V20251125\Messaging\RPC\JSONRPCResultResponse('2.0', $decoded->id(), $result),
                        };

                        $this->pipe->send($response->toJson());
                        $this->negotiated_protocol_version = $result->protocolVersion;
                    }
                }
                elseif($message instanceof InitializedNotification)
                {
                    if($this->negotiated_protocol_version)
                    {
                        // start the server
                        $this->server = new MCPServer($this->server_configuration, $this->negotiated_protocol_version);

                        // @todo - only fire if logging level is appropriate and dont' send if off.
                        $this->say("Session started on version {$this->negotiated_protocol_version}.");

                        // What came in after it, in the same read, is the started session's.
                        $this->processMail(array_slice(array_values($mail), $at + 1));
                        return;
                    }
                }
                elseif($message instanceof PingRequest && (!is_null($this->negotiated_protocol_version)))
                {
                    $request = MessageHydrator::hydrateAs($decoded, $this->negotiated_protocol_version, $message->method);
                    try {
                        $response = $request instanceof MCPError
                            ? $request
                            : ProcedureRouter::dispatch($this->negotiated_protocol_version, $request, $this->server_configuration);
                    } catch (SuperconductorMCPException $e) {
                        // The router refuses by throwing; its code and message are the JSON-RPC error.
                        $response = new HydrationError($e->getCode(), $e->getMessage());
                    }

                    $this->respond($decoded->id(), $response);
                }
                elseif($message instanceof SetLevelRequest && (!is_null($this->negotiated_protocol_version)))
                {
                    $request = MessageHydrator::hydrateAs($decoded, $this->negotiated_protocol_version, $message->method);

                    try {
                        // The router refuses logging/setLevel unless the configuration declares the logging capability.
                        $response = $request instanceof MCPError
                            ? $request
                            : ProcedureRouter::dispatch($this->negotiated_protocol_version, $request, $this->server_configuration);
                    } catch (SuperconductorMCPException $e) {
                        $response = new HydrationError($e->getCode(), $e->getMessage());
                    }

                    $this->respond($decoded->id(), $response);
                }
                elseif(! $message instanceof MCPError && $decoded instanceof DecodedRequest)
                {
                    // Hydration errors were answered above; notifications and responses have no id to answer.
                    $this->pipe->send(new JSONRPCErrorResponse('2.0', $decoded->id(), new HydrationError(
                        RPCErrorCode::INVALID_REQUEST->value,
                        "Server not initialized: [{$decoded->method}] cannot be sent before initialize.",
                    ))->toJson());
                }
            }
        }
    }

    /**
     * The schema object a decoded message is, from whichever protocol version it fits, or an MCPError saying why it is none.
     */
    private function decodeMessage(DecodedMessage $message): Result|Notification|Request|MCPError
    {
        $request_method = $message instanceof DecodedResponse || $message instanceof DecodedError
            ? $this->pending_requests[$message->id?->id] ?? null
            : null;

        return MessageHydrator::hydrate($message, $request_method);
    }

    /**
     * Sends a request's answer in the negotiated version's JSON-RPC envelope: a result response, or an error response.
     */
    private function respond(RequestId $id, Result|MCPError $answer): void
    {
        if($answer instanceof MCPError)
        {
            $error = $answer->toArray();
            $this->pipe->send(new JSONRPCErrorResponse('2.0', $id, new HydrationError($error['code'], $error['message'], $error['data'] ?? null))->toJson());
            return;
        }

        assert($answer instanceof SchemaResult);

        $response = match($this->negotiated_protocol_version) {
            '2024-11-05' => new V20241105\Messaging\RPC\JSONRPCResponse('2.0', $id, $answer),
            '2025-03-26' => new V20250326\Messaging\RPC\JSONRPCResponse('2.0', $id, $answer),
            '2025-06-18' => new V20250618\Messaging\RPC\JSONRPCResponse('2.0', $id, $answer),
            '2025-11-25' => new V20251125\Messaging\RPC\JSONRPCResultResponse('2.0', $id, $answer),
        };

        $this->pipe->send($response->toJson());
    }

    private function isUnsupportedVersion(string $protocol_version): bool
    {
        return ! in_array($protocol_version, $this->handshakeVersions(), true);
    }

    /**
     * The versions this server can open a stateful session in: those its configuration supports, less 2026-07-28, which has no handshake.
     *
     * @return list<string>
     */
    private function handshakeVersions(): array
    {
        return array_values(array_diff($this->server_configuration->supportedProtocolVersions(), ['2026-07-28']));
    }
}
