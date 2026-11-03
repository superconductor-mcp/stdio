<?php

use Fabricate\Client\ClientCapabilitiesFactory;
use Fabricate\Client\ClientConfiguration;
use Fabricate\Contracts\Mechanism\Attributes\ClientConfig;
use Fabricate\Contracts\Schema\RequestId;
use Fabricate\Core\Routing\ProcedureRouter;
use Fabricate\Schema\V20251125;
use Fabricate\Schema\V20260728;
use SuperconductorMCP\STDIO\ServerDefinitions\StdioServerDefinition;
use Voyager\Config\Repository;
use Voyager\Contracts\IOPools\Loop;
use Voyager\IOPools\EventLoop;
use Voyager\IOPools\LoopWaiter;
use Voyager\IOPools\PromiseEngines\GuzzlePromiseEngine;
use Voyager\IOPools\ResourceRegistry;
use Voyager\IOPools\Waiter\StreamSelectWaiterBackend;
use Voyager\Signals\SignalDispatcher;
use Voyager\Vessel\ControlPanel;

#[ClientConfig('asker', 'Connects over stdio.', '1.0.0')]
class AskingClient extends ClientConfiguration
{
    protected array $supported_protocol_versions = ['2025-11-25', '2026-07-28'];
}

#[ClientConfig('legacy-asker', 'Connects over stdio.', '1.0.0')]
class LegacyAskingClient extends ClientConfiguration
{
    protected array $supported_protocol_versions = ['2025-11-25'];
}

#[ClientConfig('modern-asker', 'Connects over stdio.', '1.0.0')]
class ModernAskingClient extends ClientConfiguration
{
    protected array $supported_protocol_versions = ['2026-07-28'];
}

beforeEach(function () {
    ControlPanel::setInstance($panel = new ControlPanel());
    $mcp = require __DIR__.'/../../../core/config/mcp.php';
    $mcp['clients']['initialize_timeout'] = 1;
    $panel->registerInstance('config', new Repository(['mcp' => $mcp, 'stdio' => require __DIR__.'/../../config/stdio.php']));
    $panel->registerSingleton(ProcedureRouter::class);
    $panel->registerInstance('signals', new SignalDispatcher($panel));

    $registry = new ResourceRegistry();
    $panel->registerInstance(Loop::class, new EventLoop($registry, new LoopWaiter($registry, new StreamSelectWaiterBackend(), 16_000_000), new GuzzlePromiseEngine()));

    require __DIR__.'/../../../core/routes/mcp.php';

    $this->log = tempnam(sys_get_temp_dir(), 'stdio-client');
    $this->server = fn(string $mode) => new StdioServerDefinition(PHP_BINARY, [__DIR__.'/../Fixtures/fake-server.php', $mode, $this->log]);
    $this->received = fn() => array_map(fn(string $line) => json_decode($line, true)['method'] ?? 'response', file($this->log, FILE_IGNORE_NEW_LINES));
});

afterEach(function () {
    @unlink($this->log);
    ControlPanel::setInstance(null);
});

/**
 * Sends tools/list on the connection's client and runs the loop until it is answered.
 */
function listToolsOverStdio($client): mixed
{
    $id = new RequestId('tools');
    $request = $client->active_protocol_version === '2026-07-28'
        ? new V20260728\Messaging\Tools\ListToolsRequest($id, new V20260728\Messaging\Params\PaginatedRequestParams(new V20260728\Messaging\MetaObjects\RequestMetaObject('2026-07-28', $client->configuration->capabilities('2026-07-28'))))
        : new V20251125\Messaging\Tools\ListToolsRequest($id);
    $answer = null;

    $client->request($request, $id, function($response) use (&$answer) { $answer = $response; });
    app(Loop::class)->until(function() use (&$answer) { return $answer !== null; });

    return $answer;
}

it('opens a 2026-07-28 client when the server answers server/discover, and sends no initialize.', function () {
    $connection = ($this->server)('modern')->connect(new AskingClient(new ClientCapabilitiesFactory()));

    expect($connection->client()->active_protocol_version)->toBe('2026-07-28')
        ->and($connection->server)->toBeInstanceOf(V20260728\Messaging\Discover\DiscoverResult::class)
        ->and(listToolsOverStdio($connection->client()))->toBeInstanceOf(V20260728\Messaging\Tools\ListToolsResult::class);

    $connection->close();

    expect(($this->received)())->toBe(['server/discover', 'tools/list']);
});

it('falls back to initialize when server/discover gets an error that is not a modern one.', function () {
    $connection = ($this->server)('legacy')->connect(new AskingClient(new ClientCapabilitiesFactory()));

    expect($connection->client()->active_protocol_version)->toBe('2025-11-25')
        ->and(listToolsOverStdio($connection->client()))->toBeInstanceOf(V20251125\Messaging\Tools\ListToolsResult::class);

    $connection->close();

    // The ping the server sent before initialized was answered.
    expect(($this->received)())->toBe(['server/discover', 'initialize', 'response', 'notifications/initialized', 'tools/list']);
});

it('falls back to initialize when server/discover goes unanswered.', function () {
    $connection = ($this->server)('silent')->connect(new AskingClient(new ClientCapabilitiesFactory()));

    expect($connection->client()->active_protocol_version)->toBe('2025-11-25');

    $connection->close();
});

it('does not fall back to initialize after a modern error, and says what the server supports.', function () {
    $connection = ($this->server)('refuse')->connect(new AskingClient(new ClientCapabilitiesFactory()));

    expect($connection->client())->toBeNull()
        ->and($connection->failure())->toContain('-32022')->toContain('2099-01-01')
        ->and(($this->received)())->toBe(['server/discover']);
});

it('initializes straight away when the client has no 2026-07-28.', function () {
    $connection = ($this->server)('legacy')->connect(new LegacyAskingClient(new ClientCapabilitiesFactory()));

    expect($connection->client()->active_protocol_version)->toBe('2025-11-25')
        ->and(($this->received)()[0])->toBe('initialize');

    $connection->close();
});

it('does not initialize a legacy server for a client that only speaks 2026-07-28.', function () {
    $connection = ($this->server)('legacy')->connect(new ModernAskingClient(new ClientCapabilitiesFactory()));

    expect($connection->client())->toBeNull()
        ->and($connection->failure())->toContain('initialize handshake')
        ->and(($this->received)())->toBe(['server/discover']);
});

it('disconnects when the server answers initialize in a version the client does not support.', function () {
    $connection = ($this->server)('newer')->connect(new AskingClient(new ClientCapabilitiesFactory()));

    expect($connection->client())->toBeNull()
        ->and($connection->failure())->toContain('2099-01-01')
        ->and(($this->received)())->not->toContain('notifications/initialized');
});

it('kills a server that ignores both its closed stdin and SIGTERM.', function () {
    $connection = ($this->server)('stubborn')->connect(new AskingClient(new ClientCapabilitiesFactory()));
    $started = microtime(true);

    $connection->close();

    expect(microtime(true) - $started)->toBeLessThan(6.0)
        ->and($connection->client())->toBeNull();
});

it('launches a 2026-07-28 server again when it exits, and keeps the client.', function () {
    $connection = ($this->server)('crash')->connect(new AskingClient(new ClientCapabilitiesFactory()));
    $client = $connection->client();

    listToolsOverStdio($client);

    // The server exits after answering; the relaunch clears failure() within the same turns.
    $waited = false;
    app(Loop::class)->at(0.5, function() use (&$waited) { $waited = true; });
    app(Loop::class)->until(function() use (&$waited) { return $waited; });

    expect($connection->failure())->toBeNull()
        ->and($connection->client())->toBe($client)
        ->and(listToolsOverStdio($client))->toBeInstanceOf(V20260728\Messaging\Tools\ListToolsResult::class);

    $connection->close();
});
