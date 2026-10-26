<?php

namespace SuperconductorMCP\STDIO\Tests\Fixtures;

// records every log call as [level, message, context]
final class FakeLog
{
    public array $entries = [];

    public function log(string $level, string $message, array $context = []): void
    {
        $this->entries[] = [$level, $message, $context];
    }

    public function __call(string $level, array $arguments): void
    {
        $this->log($level, $arguments[0], $arguments[1] ?? []);
    }
}
