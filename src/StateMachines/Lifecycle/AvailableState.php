<?php

namespace SuperconductorMCP\STDIO\StateMachines\Lifecycle;

use Fabricate\NutsAndBolts\Messages\Initialize\InitializeRequest;
use Fabricate\NutsAndBolts\Messages\MCPRequest;
use Fabricate\NutsAndBolts\Messages\Ping\PingRequest;

class AvailableState extends LifecycleState
{
    public function canProcess(MCPRequest $request): bool
    {
        return match($request::class)
        {
            InitializeRequest::class => false,
            default => true
        };
    }

    public function nextState(): LifecycleState
    {

    }
}