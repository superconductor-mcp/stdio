<?php

use Voyager\Config\Repository;
use Voyager\Vessel\ControlPanel;
use Voyager\IOPools\EventLoop;
use Voyager\IOPools\LoopWaiter;
use Voyager\IOPools\ResourceRegistry;
use Voyager\Contracts\IOPools\Loop;
use Voyager\IOPools\Waiter\StreamSelectWaiterBackend;
use Voyager\IOPools\PromiseEngines\GuzzlePromiseEngine;
use SuperconductorMCP\STDIO\Tests\Fixtures\FakeLog;

function testLoop(int $pace_ms = 5): EventLoop
{
    $registry = new ResourceRegistry();

    return new EventLoop($registry, new LoopWaiter($registry, new StreamSelectWaiterBackend(), $pace_ms * 1_000_000), new GuzzlePromiseEngine());
}

// a container holding only what the packages read: both configs merged, a log that records, and the loop
function bareApp(array $stdio = [], array $mcp = [], ?EventLoop $loop = null): ControlPanel
{
    $app = new ControlPanel();
    ControlPanel::setInstance($app);

    $app->registerInstance('config', new Repository([
        'mcp' => array_replace_recursive(require dirname(__DIR__, 2).'/mcp/config/mcp.php', $mcp),
        'stdio' => array_replace_recursive(require dirname(__DIR__).'/config/stdio.php', $stdio),
    ]));
    $app->registerInstance('log', new FakeLog());
    $app->registerInstance(Loop::class, $loop ?? testLoop());

    return $app;
}

function log_entries(): array
{
    return app('log')->entries;
}

// the fixture server as an mcp_servers entry, run by the PHP that runs the tests
function fixtureServer(string ...$args): array
{
    return ['type' => 'stdio', 'command' => PHP_BINARY, 'args' => [__DIR__.'/Fixtures/echo-server.php', ...$args]];
}
