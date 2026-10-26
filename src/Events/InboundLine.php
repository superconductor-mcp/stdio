<?php

namespace SuperconductorMCP\STDIO\Events;

readonly class InboundLine
{
    public function __construct(
        public string $line
    ) {}
}