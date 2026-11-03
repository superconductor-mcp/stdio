<?php

namespace SuperconductorMCP\STDIO\Runner\Sketches;

use App\Runner\Sketches\Sketch as BaseSketch;
use Voyager\Contracts\Sketches\SketchLoopResult;
use Voyager\Contracts\IOPools\Loop as LoopInterface;
use Voyager\Contracts\Sketches\Attributes\Sketch as SketchDef;

#[SketchDef('mcp:cli {server : The name of the MCP server to start}')]
class StatelessCLIServerSketch extends BaseSketch
{
    public function __construct(
        protected LoopInterface $event_loop,
    ) {

    }

    public function boot(): void
    {

    }

    public function loop(array $mail = []): SketchLoopResult
    {

    }

    public function shutdown(): void
    {

    }
}
