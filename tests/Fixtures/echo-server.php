<?php

// a stand-in MCP server: answers initialize, ping and tools/list, pings the client once asked, exits on EOF
// `--die` exits with code 3 after a line on stderr, as a misconfigured server would

if (in_array('--die', $argv, true)) {
    fwrite(STDERR, "no config: NEO4J_PASSWORD is required\n");
    exit(3);
}

fwrite(STDERR, "echo-server up\n");

while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true);

    if (! is_array($message) || ! isset($message['method'])) {
        continue;
    }

    $answer = match ($message['method']) {
        'initialize' => ['result' => ['protocolVersion' => $message['params']['protocolVersion'], 'capabilities' => ['tools' => []], 'serverInfo' => ['name' => 'echo-server', 'version' => '1']]],
        'ping' => ['result' => []],
        'tools/list' => ['result' => ['tools' => [['name' => 'noop', 'inputSchema' => ['type' => 'object']]]]],
        'tools/call' => ['result' => ['content' => [['type' => 'text', 'text' => json_encode($message['params']['arguments'] ?? [])]]]],
        default => null,
    };

    if (! is_null($answer) && array_key_exists('id', $message)) {
        fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], ...$answer])."\n");
    }

    // the client can ask this server to ping it back, to prove it answers server requests
    if ($message['method'] === 'tools/call' && ($message['params']['name'] ?? '') === 'ping-me') {
        fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'ping'])."\n");
    }
}
