<?php

namespace SuperconductorMCP\STDIO\StateMachines\Lifecycle;

use Fabricate\NutsAndBolts\Messages\Initialize\InitializeRequest;
use Fabricate\NutsAndBolts\Messages\MCPRequest;
use Fabricate\NutsAndBolts\Messages\Ping\PingRequest;
use Fabricate\NutsAndBolts\Messages\Logging\SetLevelRequest;

class HandshakeState extends LifecycleState
{
    public function canProcess(MCPRequest $request): bool
    {
        return match($request::class)
        {
            InitializeRequest::class,
            PingRequest::class,
            SetLevelRequest::class => true,
            default => false
        };
    }

    public function nextState(): LifecycleState
    {
        return new AvailableState();
    }
}