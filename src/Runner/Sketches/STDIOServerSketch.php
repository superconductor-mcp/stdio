<?php

namespace SuperconductorMCP\STDIO\Runner\Sketches;


use ReflectionException;
use Voyager\Workflows\Flow;
use Fabricate\Contracts\Server\MCPServer;
use Voyager\Contracts\IOPools\Loop;
use SuperconductorMCP\STDIO\STDIOBag;
use Fabricate\Server\ServerRepository;
use SuperconductorMCP\STDIO\Pipes\StdioPipe;
use Voyager\Contracts\Sketches\SketchLoopResult;
use Voyager\Contracts\Sketches\Attributes\Sketch;
use Fabricate\Core\Runner\Sketches\StartMCPServer;
use SuperconductorMCP\STDIO\Workflows\STDIOLoopStartNode;

#[Sketch('mcp:cli {server : The name of the MCP server to start}')]
class STDIOServerSketch extends StartMCPServer
{
    protected ?StdioPipe $pipe = null;

    protected STDIOBag $bag;

    protected ?MCPServer $server_configuration = null;

    protected string $description = 'Start an STDIO-driven MCP Server';

    public function __construct(
        protected readonly Loop $event_loop,
        protected readonly ServerRepository $servers,
    ) {
        $this->bag = new STDIOBag();
    }

    /**
     * @throws ReflectionException
     */
    public function boot(): void
    {
        $this->registerStdioResources();
        $this->registerStdioNotificationActions();
        $this->pullServerConfiguration();
    }

    public function loop(array $mail = []): SketchLoopResult
    {
        if(!empty($mail))
        {
            $result = new Flow(
                new STDIOLoopStartNode($mail, $this->pipe, $this->event_loop)
            )->run($this->bag);

            if(in_array($result, ['pipe-closed', 'handshake-timed-out', 'client-unresponsive'], true))
            {
                return SketchLoopResult::STOP;
            }
        }

        // notifications queued outside any request (timers, sensors, background work) go out on the next frame
        STDIOLoopStartNode::flushOutbox($this->bag, $this->pipe, $this->event_loop);

        return SketchLoopResult::CONTINUE;
    }

    public function shutdown(): void
    {
        $this->bag->stop_waiting?->cancel();
        $this->bag->ping_timer?->cancel();
        $this->event_loop->forget(StdioPipe::NAME);
        $this->pipe?->release();
        $this->pipe = null;
    }

    /**
     * @throws ReflectionException
     */
    private function registerStdioNotificationActions(): void
    {
        $notification_map = config('stdio.notifications', []);
        $this->bag->put('notifications', $notification_map);
    }

    private function pullServerConfiguration(): void
    {
        // an unknown name throws from the repository, so boot fails before the first frame
        $this->server_configuration = $this->servers->get((string) $this->argument('server'));
        $this->bag->server_configuration = $this->server_configuration;

        // tools that ask for their server get this running instance, under its own class or the abstract one
        app()->registerInstance($this->server_configuration::class, $this->server_configuration);
        app()->registerInstance(MCPServer::class, $this->server_configuration);
    }

    private function registerStdioResources(): void
    {
        $this->routeErrorsToStdErr();
        $this->pipe = new StdioPipe(STDIN, STDOUT, $this->event_loop);
        $this->event_loop->resource(StdioPipe::NAME, $this->pipe);
    }

    private function routeErrorsToStdErr(): void
    {
        // stdout carries protocol frames only; a PHP warning printed there corrupts the stream
        ini_set('display_errors', 'stderr');
    }
}