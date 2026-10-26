<?php

use Fabricate\Client\MCPClient;
use Fabricate\Client\ClientStatus;
use Voyager\Contracts\IOPools\Loop;
use SuperconductorMCP\STDIO\Client\StdioConnection;

function spawnClient(array $definition, array $stdio = [], array $mcp = []): array
{
    $loop = testLoop();
    $app = bareApp($stdio, $mcp, $loop);
    $client = new MCPClient('bench', $loop);
    $client->attach(new StdioConnection($definition, $loop, $client));

    return [$client, $loop];
}

it('runs a server as a child process and talks to it over its pipes', function () {
    [$client, $loop] = spawnClient(fixtureServer());

    $client->open();
    expect($client->ready()->wait())->toBe($client)
        ->and($client->serverInfo()['name'])->toBe('echo-server')
        ->and(array_column($client->tools(), 'name'))->toBe(['noop']);

    $client->ping()->wait();

    // the server's own request gets answered: its ping comes back as a reply with its id
    $client->callTool('ping-me')->wait();
    $loop->until(fn (): bool => str_contains(json_encode(log_entries()), 'mcp.incoming ping'));

    $client->close();
    $loop->until(fn (): bool => $client->closed());

    expect($client->status())->toBe(ClientStatus::NotConnected);
});

it('reports a server that exits before the handshake, with its exit code and last stderr', function () {
    [$client, $loop] = spawnClient(fixtureServer('--die'));

    $client->open();
    $loop->until(fn (): bool => $client->status() !== ClientStatus::Connecting);

    expect($client->status())->toBe(ClientStatus::Error)
        ->and($client->lastError())->toContain('exit code 3')
        ->and($client->lastError())->toContain('NEO4J_PASSWORD is required');
    expect(fn () => $client->ready()->wait())->toThrow(\Fabricate\Client\RequestFailed::class);
});

it('escalates to SIGTERM on a loop timer when the server ignores its closed stdin', function () {
    [$client, $loop] = spawnClient(['type' => 'stdio', 'command' => 'sleep', 'args' => ['30']], ['client' => ['shutdown_grace' => 0.1]]);

    $client->open();
    $started = microtime(true);
    $client->close();

    expect($client->closed())->toBeFalse();   // nothing blocks: the wait runs on the loop
    $loop->until(fn (): bool => $client->closed());

    expect(microtime(true) - $started)->toBeLessThan(1.0);
});

it('names a command that cannot start', function () {
    [$client, $loop] = spawnClient(['type' => 'stdio', 'command' => '/nonexistent/mcp-server']);

    $client->open();
    $loop->until(fn (): bool => $client->status() !== ClientStatus::Connecting);

    expect($client->status())->toBe(ClientStatus::Error)->and($client->lastError())->toContain('not an executable');
});
