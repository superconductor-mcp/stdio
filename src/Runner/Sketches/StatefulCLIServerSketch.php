<?php

namespace SuperconductorMCP\STDIO\Runner\Sketches;

use JsonException;
use ReflectionException;
use Fabricate\Server\ServerRegistry;
use Fabricate\Server\ServerConfiguration;
use App\Runner\Sketches\Sketch as BaseSketch;
use Voyager\Contracts\Sketches\SketchLoopResult;
use Voyager\Contracts\IOPools\Loop as LoopInterface;
use Voyager\Contracts\Sketches\Attributes\Sketch as SketchDef;
use SuperconductorMCP\STDIO\Concerns\StatefulServerFunctionality;

#[SketchDef('mcp:cli-legacy {server : The name of the MCP server to start}')]
class StatefulCLIServerSketch extends BaseSketch
{
    use StatefulServerFunctionality;

    protected ?ServerConfiguration $server_configuration = null;

    protected string $description = 'Start an STDIO-driven MCP Server with a state.';

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
        $this->pullServerConfiguration(
            $this->argument('server'),
            $this->servers,
            $this->server_configuration
        );
        $this->setupEmergencyStop();
    }

    /**
     * @throws ReflectionException
     * @throws JsonException
     */
    public function loop(array $mail = []): SketchLoopResult
    {
        $this->work($mail);

        return $this->shouldContinue()
            ? SketchLoopResult::CONTINUE
            : SketchLoopResult::STOP
        ;
    }

    public function shutdown(): void
    {
        $this->closePipeResource($this->event_loop, $this->pipe);
    }
}
