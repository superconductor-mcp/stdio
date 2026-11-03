<?php

// A stdio MCP server that behaves one way per mode, writing each line it reads to $argv[2].
[, $mode, $log] = $argv;

$send = fn(array $message) => fwrite(STDOUT, json_encode($message)."\n");
$discovered = ['_meta' => [], 'resultType' => 'complete', 'ttlMs' => 0, 'cacheScope' => 'private', 'supportedVersions' => ['2026-07-28'], 'capabilities' => (object) []];
$initialized = fn(string $version) => ['protocolVersion' => $version, 'capabilities' => (object) [], 'serverInfo' => ['name' => 'fake', 'version' => '1']];

if($mode === 'stubborn') pcntl_signal(SIGTERM, SIG_IGN);

// dying: the first process answers server/discover and exits on tools/list; every later one exits before reading a line.
$launches = (int) @file_get_contents("{$log}.launches") + 1;
file_put_contents("{$log}.launches", (string) $launches);
if($mode === 'dying' && $launches > 1) exit(1);

while(true)
{
    if($mode === 'stubborn') pcntl_signal_dispatch();

    $line = fgets(STDIN);

    if($line === false)
    {
        // Leaves only when told the hard way.
        if($mode === 'stubborn') { sleep(30); }
        exit(0);
    }

    file_put_contents($log, $line, FILE_APPEND);
    $message = json_decode($line, true);
    [$id, $method] = [$message['id'] ?? null, $message['method'] ?? null];

    match(true) {
        $method === 'server/discover' && in_array($mode, ['modern', 'crash', 'stubborn', 'vanish', 'dying'], true) => $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $discovered]),
        $method === 'server/discover' && $mode === 'refuse' => $send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32022, 'message' => 'Unsupported protocol version', 'data' => ['supported' => ['2099-01-01'], 'requested' => '2026-07-28']]]),
        $method === 'server/discover' && in_array($mode, ['legacy', 'newer', 'quit'], true) => $send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']]),
        $method === 'server/discover' => null, // silent
        $method === 'initialize' => [
            $mode === 'legacy' || $mode === 'silent' ? $send(['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 'early']) : null,
            $mode === 'legacy' ? $send(['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => ['level' => 'info', 'data' => 'starting']]) : null,
            $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $initialized($mode === 'newer' ? '2099-01-01' : '2025-11-25')]),
        ],
        $method === 'tools/list' && in_array($mode, ['quit', 'vanish', 'dying'], true) => exit(1),
        $method === 'tools/list' => [
            $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => ($message['params']['_meta'] ?? false) ? ['_meta' => [], 'resultType' => 'complete', 'ttlMs' => 0, 'cacheScope' => 'private', 'tools' => []] : ['tools' => []]]),
            $mode === 'crash' ? exit(1) : null,
        ],
        default => null,
    };
}
