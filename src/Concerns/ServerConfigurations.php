<?php

namespace SuperconductorMCP\STDIO\Concerns;

use Fabricate\Server\ServerRegistry;
use Fabricate\Server\ServerConfiguration;

trait ServerConfigurations
{
    protected function pullServerConfiguration(
        string $argument,
        ServerRegistry $servers,
        ?ServerConfiguration &$server = null
    ): void
    {
        $server = $servers->get($argument);
    }
}
