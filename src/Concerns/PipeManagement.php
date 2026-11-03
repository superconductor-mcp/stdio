<?php

namespace SuperconductorMCP\STDIO\Concerns;

use ReflectionException;
use SuperconductorMCP\STDIO\Pipes\StdioServerPipe;
use Voyager\Contracts\IOPools\Loop as LoopInterface;

trait PipeManagement
{
    protected ?StdioServerPipe $pipe = null;

    /**
     * @param LoopInterface $event_loop
     * @param StdioServerPipe|null $pipe
     * @return void
     * @throws ReflectionException
     */

    protected function registerPipeResource(
        LoopInterface $event_loop,
        ?StdioServerPipe &$pipe = null
    ): void
    {
        ini_set('display_errors', 'stderr');
        $pipe = new StdioServerPipe(STDIN, STDOUT, $event_loop);
        $event_loop->resource($pipe->name, $pipe);
    }

    /**
     * @param LoopInterface $event_loop
     * @param StdioServerPipe $pipe
     * @return void
     */
    protected function closePipeResource(
        LoopInterface $event_loop,
        StdioServerPipe &$pipe
    ): void
    {
        $event_loop->forget($this->pipe->name);
        $pipe?->release();
        $pipe = null;
    }

    protected function say(string $words): void
    {
        fwrite(STDERR, "{$words}\n");
    }

}
