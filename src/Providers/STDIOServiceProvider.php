<?php

namespace SuperconductorMCP\STDIO\Providers;

use Voyager\NutsAndBolts\ServiceProvider;
use SuperconductorMCP\STDIO\Runner\Sketches\StatefulCLIServerSketch;

class STDIOServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/stdio.php', 'stdio');

        $this->app['config']->push('sketches.load', StatefulCLIServerSketch::class);
    }

    public function boot(): void
    {

    }
}
