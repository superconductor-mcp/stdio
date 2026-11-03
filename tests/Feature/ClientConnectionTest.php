<?php

use Fabricate\Client\ClientCapabilitiesFactory;
use Fabricate\Client\ClientConfiguration;
use Fabricate\Client\Signals\ConnectionFailed;
use Fabricate\Client\Signals\NotificationReceived;
use Fabricate\Contracts\Schema\Messaging\MCPError;
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
    $mcp = require \Composer\InstalledVersions::getInstallPath('superconductor/core').'/config/mcp.php';
    // Three seconds, not one: a cold PHP start under load can take most of a second, which made the probe fall back.
    $mcp['clients']['initialize_timeout'] = 3;
    $panel->registerInstance('config', new Repository(['mcp' => $mcp, 'stdio' => require __DIR__.'/../../config/stdio.php']));
    $panel->registerSingleton(ProcedureRouter::class);
    $panel->registerInstance('signals', new SignalDispatcher($panel));

    $registry = new ResourceRegistry();
    $panel->registerInstance(Loop::class, new EventLoop($registry, new LoopWaiter($registry, new StreamSelectWaiterBackend(), 16_000_000), new GuzzlePromiseEngine()));

    require \Composer\InstalledVersions::getInstallPath('superconductor/core').'/routes/mcp.php';

    $this->log = tempnam(sys_get_temp_dir(), 'stdio-client');
    $this->server = fn(string $mode) => new StdioServerDefinition(PHP_BINARY, [__DIR__.'/../Fixtures/fake-server.php', $mode, $this->log]);
    $this->received = fn() => array_map(fn(string $line) => json_decode($line, true)['method'] ?? 'response', file($this->log, FILE_IGNORE_NEW_LINES));
});

afterEach(function () {
    @unlink($this->log);
    if(file_exists("{$this->log}.launches")) unlink("{$this->log}.launches");
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

it('hears what the server sends before it answers initialize, once the client exists.', function () {
    $heard = [];
    app('signals')->listen(NotificationReceived::class, function(NotificationReceived $signal) use (&$heard) { $heard[] = $signal->notification->method; });

    $connection = ($this->server)('legacy')->connect(new AskingClient(new ClientCapabilitiesFactory()));
    $connection->close();

    expect($heard)->toBe(['notifications/message']);
});

it('fails the connection when a legacy server exits mid-session: the application hears it, and requests in flight get the error.', function () {
    $failed = null;
    app('signals')->listen(ConnectionFailed::class, function(ConnectionFailed $signal) use (&$failed) { $failed = $signal; });

    $connection = ($this->server)('quit')->connect(new AskingClient(new ClientCapabilitiesFactory()));
    $client = $connection->client();
    $answer = null;

    $client->request(new V20251125\Messaging\Tools\ListToolsRequest(new RequestId('tools')), new RequestId('tools'), function($response) use (&$answer) { $answer = $response; });
    app(Loop::class)->until(function() use (&$answer) { return $answer !== null; });

    expect($answer)->toBeInstanceOf(MCPError::class)
        ->and($failed)->toBeInstanceOf(ConnectionFailed::class)
        ->and($failed->session_id)->toBe($client->session_id)
        ->and($answer->toArray()['message'])->toBe($failed->reason)
        ->and($connection->failure())->toBe($failed->reason)
        ->and($connection->client())->toBeNull();
});

it('answers what a 2026-07-28 server left in flight when it exited, and keeps the client for the relaunched one.', function () {
    $connection = ($this->server)('vanish')->connect(new AskingClient(new ClientCapabilitiesFactory()));
    $client = $connection->client();

    $lost = listToolsOverStdio($client);

    expect($lost)->toBeInstanceOf(MCPError::class)
        ->and($lost->toArray()['message'])->toContain('launched again')
        ->and($connection->client())->toBe($client);

    // The relaunch clears failure() within the same turns.
    $waited = false;
    app(Loop::class)->at(0.5, function() use (&$waited) { $waited = true; });
    app(Loop::class)->until(function() use (&$waited) { return $waited; });

    expect($connection->failure())->toBeNull();

    $connection->close();
});

it('stops relaunching a 2026-07-28 server that exits before it is heard from again, and fails the connection.', function () {
    $failed = null;
    app('signals')->listen(ConnectionFailed::class, function(ConnectionFailed $signal) use (&$failed) { $failed = $signal; });

    $connection = ($this->server)('dying')->connect(new AskingClient(new ClientCapabilitiesFactory()));
    $client = $connection->client();

    $lost = listToolsOverStdio($client);

    $deadline = false;
    app(Loop::class)->at(3, function() use (&$deadline) { $deadline = true; });
    app(Loop::class)->until(function() use (&$failed, &$deadline) { return ! is_null($failed) || $deadline; });

    expect($failed)->toBeInstanceOf(ConnectionFailed::class);

    // Time for any further relaunch to show up in the count.
    $waited = false;
    app(Loop::class)->at(0.5, function() use (&$waited) { $waited = true; });
    app(Loop::class)->until(function() use (&$waited) { return $waited; });

    expect($lost)->toBeInstanceOf(MCPError::class)
        ->and((int) file_get_contents("{$this->log}.launches"))->toBe(2)
        ->and($failed->session_id)->toBe($client->session_id)
        ->and($connection->failure())->toBe($failed->reason)
        ->and($connection->client())->toBeNull();
});

it('fails the connection, without throwing, when the server command cannot start.', function () {
    $connection = new StdioServerDefinition('/nonexistent/mcp-server')->connect(new AskingClient(new ClientCapabilitiesFactory()));

    expect($connection->client())->toBeNull()
        ->and($connection->failure())->toContain('/nonexistent/mcp-server');
});
