<?php

namespace SuperconductorMCP\STDIO\Concerns;

use Fabricate\Contracts\Mechanism\DecodedMessage;
use Fabricate\Contracts\Schema\RPCErrorCode;
use Fabricate\Mechanism\Decoding\InvalidMessage;
use Fabricate\Mechanism\Mail\OutboundMessage;
use Fabricate\Server\MCPServer;
use ReflectionException;
use SuperconductorMCP\STDIO\Events\BatchReceived;
use SuperconductorMCP\STDIO\Events\PipeClosed;
use SuperconductorMCP\STDIO\Events\ServerClosed;
use Voyager\Contracts\IOPools\Loop;

/**
 * A 2026-07-28 server over stdio. There is no handshake: every request names its protocol version and client
 * capabilities in _meta and is answered on its own. Everything goes out on stdout, oldest first: answers, notifications
 * sent while answering, and subscription notifications, which carry their subscription id. Closing stdin ends it.
 */
trait StatelessServerFunctionality
{
    use PipeManagement, ServerConfigurations;

    private ?MCPServer $server = null;

    private bool $should_continue = true;

    protected function shouldContinue(): bool
    {
        return $this->should_continue;
    }

    /**
     * The one server answering for this process, in the stateless protocol version.
     */
    protected function startServer(): void
    {
        $this->server = new MCPServer($this->server_configuration, '2026-07-28');
    }

    protected function work(array $mail = []): void
    {
        $this->processMail($mail);
        $this->fireOutgoingMessages();
    }

    protected function fireOutgoingMessages(): void
    {
        foreach($this->server->pullQueued() as $message) $this->pipe->send($message->toJson());
    }

    protected function processMail(array $mail): void
    {
        foreach($mail as $message)
        {
            if($message instanceof PipeClosed)
            {
                // Ending the server cancels and answers its open subscriptions, as mail; the process ends once it's sent.
                $this->server->close();
                app(Loop::class)->post(new ServerClosed());
                return;
            }

            if($message instanceof ServerClosed)
            {
                signal('pipe-closed');
                return;
            }

            if($message instanceof BatchReceived)
            {
                // 2026-07-28 carries one JSON-RPC message per line.
                $this->pipe->send(new InvalidMessage(null, RPCErrorCode::INVALID_REQUEST, 'Batches are not part of 2026-07-28: send one message per line.')->toJson());
            }
            elseif($message instanceof OutboundMessage)
            {
                if($message->session_id === $this->server->session_id) $this->server->queue($message->message());
            }
            elseif($message instanceof DecodedMessage && ($reply = $this->server->receiveMessage($message)))
            {
                $this->server->queue($reply);
            }
        }
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
}
