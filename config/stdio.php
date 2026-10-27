<?php

use Fabricate\Mechanism\Session\Actions\CancelledAction;
use Fabricate\Mechanism\Session\Actions\InitializedAction;

return [
    'handshake' => [
        'stop_waiting' => [
            'enabled' => true,
            'ttl' => 60,
        ],
    ],
    'ping_time' => [
        'enabled' => true,
        'ttl' => 10,
        'max_misses' => 3,
    ],
    'client' => [
        // seconds to wait for a server to exit after its stdin closes, and again after SIGTERM, before escalating
        'shutdown_grace' => 2,
    ],
    'notifications' => [
        'notifications/initialized' => InitializedAction::class,
        'notifications/cancelled' => CancelledAction::class
    ]
];