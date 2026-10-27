<?php

namespace SuperconductorMCP\STDIO;

use Fabricate\Mechanism\Session\SessionBag;
use Voyager\Contracts\IOPools\LoopResources\Timer;

// the session bag plus what only a pipe needs: the handshake deadline and the server's own pings
class STDIOBag extends SessionBag
{
    public ?Timer $stop_waiting = null;

    public ?Timer $ping_timer = null;

    /** @var array<int, true> ids of server pings the client has not answered yet */
    public array $pending_pings = [];

    // unanswered pings in a row; any answer from the client resets it
    public int $ping_misses = 0;
}
