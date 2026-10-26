<?php

namespace SuperconductorMCP\STDIO\Providers;

use Fabricate\Client\MCPClient;
use Fabricate\Client\ClientManager;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\Core\FrameworkCore;
use SuperconductorMCP\STDIO\Client\StdioConnection;
use SuperconductorMCP\STDIO\Runner\Sketches\STDIOServerSketch;
use Voyager\NutsAndBolts\ServiceProvider;

class STDIOServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/stdio.php', 'stdio');

        // Rocket only discovers sketches under the app's own paths; a package's sketch joins through sketches.load.
        $this->app['config']->push('sketches.load', STDIOServerSketch::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/stdio.php' => config_path('stdio.php'),
        ], 'stdio-config');

        // an mcp_servers entry of type stdio becomes a client whose connection is the server's own pipes
        $this->app->make(ClientManager::class)->extend('stdio', function (FrameworkCore $app, array $config): MCPClient {
            $loop = $app->make(Loop::class);
            $client = new MCPClient($config['name'], $loop);

            return $client->attach(new StdioConnection($config, $loop, $client));
        });
    }
}
