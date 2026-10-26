<?php

namespace SuperconductorMCP\STDIO\Actions;

use SuperconductorMCP\STDIO\STDIOBag;
use Fabricate\NutsAndBolts\Messages\Cancellation\CancelledNotification;

class CancelledAction
{
    public function handle(STDIOBag &$bag, CancelledNotification $notification): void
    {
        // both sides should log cancellation reasons
        app('log')->info('mcp.stdio request cancelled by client', [
            'request_id' => $notification->request_id,
            'reason' => $notification->reason,
            'in_flight' => isset($bag->in_flight[$notification->request_id]),
        ]);

        // an unknown or already-finished request is ignored; one still running has its response suppressed
        if (isset($bag->in_flight[$notification->request_id])) {
            $bag->cancelled[$notification->request_id] = true;
        }
    }
}
