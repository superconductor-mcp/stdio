<?php

namespace SuperconductorMCP\STDIO\Actions;

use SuperconductorMCP\STDIO\STDIOBag;
use Fabricate\NutsAndBolts\Messages\Initialize\InitializedNotification;

class InitializedAction
{
    public function handle(STDIOBag &$bag, ?InitializedNotification $notification = null): void
    {
        $bag->state = $bag->state->nextState();

        // the handshake is complete: stop_waiting no longer applies
        $bag->stop_waiting?->cancel();
        $bag->stop_waiting = null;
    }
}