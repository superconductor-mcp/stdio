<?php

namespace SuperconductorMCP\STDIO\Runner\Sketches;

use Voyager\Sketches\Sketch as BaseSketch;
use Fabricate\Server\ServerConfiguration;
use Fabricate\Server\ServerRegistry;
use ReflectionException;
use SuperconductorMCP\STDIO\Concerns\StatelessServerFunctionality;
use Voyager\Contracts\IOPools\Loop as LoopInterface;
use Voyager\Contracts\Sketches\Attributes\Sketch as SketchDef;
use Voyager\Contracts\Sketches\SketchLoopResult;

#[SketchDef('mcp:cli {server : The name of the MCP server to start}')]
class StatelessCLIServerSketch extends BaseSketch
{
    use StatelessServerFunctionality;

    protected ?ServerConfiguration $server_configuration = null;

    protected string $description = 'Start an STDIO-driven MCP Server without a state (2026-07-28).';

    public function __construct(
        protected LoopInterface $event_loop,
        protected ServerRegistry $servers,
    ) {}

    /**
     * @throws ReflectionException
     */
    public function boot(): void
    {
        $this->registerPipeResource($this->event_loop, $this->pipe);
        $this->pullServerConfiguration($this->argument('server'), $this->servers, $this->server_configuration);
        $this->setupEmergencyStop();
        $this->startServer();
    }

    public function loop(array $mail = []): SketchLoopResult
    {
        $this->work($mail);

        return $this->shouldContinue() ? SketchLoopResult::CONTINUE : SketchLoopResult::STOP;
    }

    public function shutdown(): void
    {
        $this->closePipeResource($this->event_loop, $this->pipe);
    }
}
