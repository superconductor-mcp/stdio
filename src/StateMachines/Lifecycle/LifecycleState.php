<?php

namespace SuperconductorMCP\STDIO\StateMachines\Lifecycle;

use Fabricate\NutsAndBolts\Messages\MCPRequest;

abstract class LifecycleState
{
    abstract public function canProcess(MCPRequest $request): bool;
    abstract public function nextState(): LifecycleState;
}