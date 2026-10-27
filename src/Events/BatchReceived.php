<?php

namespace SuperconductorMCP\STDIO\Events;

// one stdin line held a JSON-RPC batch; whether it is taken depends on the version the session negotiated
readonly class BatchReceived
{
    /** @param list<object> $messages the decoded elements, in order */
    public function __construct(public array $messages) {}
}
