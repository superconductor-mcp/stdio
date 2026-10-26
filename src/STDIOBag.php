<?php

namespace SuperconductorMCP\STDIO;

use Fabricate\NutsAndBolts\MCPServerBag;
use Fabricate\NutsAndBolts\RequestIds;
use SuperconductorMCP\STDIO\StateMachines\Lifecycle\IdleState;
use SuperconductorMCP\STDIO\StateMachines\Lifecycle\LifecycleState;
use Voyager\Contracts\IOPools\LoopResources\Timer;

class STDIOBag extends MCPServerBag
{
    public LifecycleState $state;
    // the spec's id rule is per requestor: the client may not repeat its own ids, the server may not repeat its own
    public RequestIds $their_ids;
    public RequestIds $own_ids;
    public ?Timer $stop_waiting = null;
    public ?Timer $ping_timer = null;

    /** @var array<int, true> ids of server pings the client has not answered yet */
    public array $pending_pings = [];

    // unanswered pings in a row; any answer from the client resets it
    public int $ping_misses = 0;

    /** @var array<string|int, true> ids of client requests still being handled */
    public array $in_flight = [];

    /** @var array<string|int, true> in-flight requests the client cancelled; their responses are not sent */
    public array $cancelled = [];


    public function __construct()
    {
        $this->state = new IdleState();
        $this->their_ids = new RequestIds();
        $this->own_ids = new RequestIds();
    }
}