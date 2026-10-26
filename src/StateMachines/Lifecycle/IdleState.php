<?php

namespace SuperconductorMCP\STDIO\StateMachines\Lifecycle;

use Fabricate\NutsAndBolts\Messages\Initialize\InitializeRequest;
use Fabricate\NutsAndBolts\Messages\MCPRequest;

class IdleState extends LifecycleState
{
    public function canProcess(MCPRequest $request): bool
    {
        return match($request::class)
        {
            InitializeRequest::class => true,
            default => false
        };
    }

    public function nextState(): HandshakeState
    {
        return new HandshakeState();
    }
}