<?php

namespace SuperconductorMCP\STDIO\Providers;

use Voyager\NutsAndBolts\ServiceProvider;
use SuperconductorMCP\STDIO\Runner\Sketches\StatefulCLIServerSketch;
use SuperconductorMCP\STDIO\Runner\Sketches\StatelessCLIServerSketch;

class STDIOServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/stdio.php', 'stdio');

        $this->app['config']->push('sketches.load', StatefulCLIServerSketch::class);
        $this->app['config']->push('sketches.load', StatelessCLIServerSketch::class);
    }

    public function boot(): void
    {

    }
}
