<?php

namespace SuperconductorMCP\STDIO\Pipes;

use Closure;
use Voyager\Contracts\IOPools\WakeReason;
use Voyager\IOPools\Resources\WakeSource;
use Voyager\IOPools\Waiter\Wakes\Readable;
use Voyager\IOPools\Waiter\Wakes\Writable;

/**
 * The client's end of a stdio server's standard streams: lines to its stdin, lines from its stdout. Its stderr is
 * drained and dropped, which the stdio transport allows.
 */
class StdioClientPipe extends WakeSource
{
    private readonly int $read_chunk_size;

    private string $inbound = '';

    private string $outbound = '';

    private bool $stdin_open = true;

    private bool $stdout_open = true;

    private bool $stderr_open = true;

    /**
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     * @param Closure(string): void $on_line Each line the server writes.
     * @param Closure(string): void $on_end Why the server can no longer be talked to.
     * @param Closure(): void $before_wait Runs before the loop waits, so what was queued since goes out.
     */
    public function __construct(
        private readonly mixed $stdin,
        private readonly mixed $stdout,
        private readonly mixed $stderr,
        private readonly Closure $on_line,
        private readonly Closure $on_end,
        private readonly Closure $before_wait,
    ) {
        $this->read_chunk_size = config('stdio.read_chunk_size');

        stream_set_read_buffer($this->stdout, 0);
        stream_set_write_buffer($this->stdin, 0);

        foreach([$this->stdin, $this->stdout, $this->stderr] as $stream) stream_set_blocking($stream, false);
    }

    public function send(string $line): void
    {
        $this->outbound .= $line."\n";

        // write now; the Writable wake only carries whatever the pipe refused
        $this->flush();
    }

    /**
     * Closing the server's stdin is how the stdio transport asks it to exit.
     */
    public function closeStdin(): void
    {
        if($this->stdin_open)
        {
            $this->stdin_open = false;
            fclose($this->stdin);
        }
    }

    public function stdoutOpen(): bool
    {
        return $this->stdout_open;
    }

    public function wakes(): array
    {
        ($this->before_wait)();

        $wakes = [];

        if($this->stdout_open) $wakes[] = new Readable($this->stdout);
        if($this->stderr_open) $wakes[] = new Readable($this->stderr);

        // stdin is almost always writable, so it is watched only while bytes are waiting
        if($this->stdin_open && $this->outbound !== '') $wakes[] = new Writable($this->stdin);

        return $wakes;
    }

    public function woke(array $fired): void
    {
        foreach($fired as $wake)
        {
            match(true) {
                $wake->kind() === WakeReason::WRITEABLE => $this->flush(),
                $wake->stream === $this->stdout => $this->readStdout(),
                $wake->stream === $this->stderr => $this->drainStderr(),
                default => null,
            };
        }
    }

    private function readStdout(): void
    {
        while(($chunk = fread($this->stdout, $this->read_chunk_size)) !== false && $chunk !== '') {
            $this->inbound .= $chunk;
        }

        while(($at = strpos($this->inbound, "\n")) !== false)
        {
            $line = rtrim(substr($this->inbound, 0, $at), "\r");
            $this->inbound = substr($this->inbound, $at + 1);

            if($line !== '') ($this->on_line)($line);
        }

        // an empty read means "nothing yet" or "server gone"; only EOF says which
        if(feof($this->stdout))
        {
            $this->stdout_open = false;
            ($this->on_end)('The server closed its stdout.');
        }
    }

    // a full stderr pipe would block the server
    private function drainStderr(): void
    {
        while(($chunk = fread($this->stderr, $this->read_chunk_size)) !== false && $chunk !== '') {}

        if(feof($this->stderr)) $this->stderr_open = false;
    }

    private function flush(): void
    {
        if($this->outbound === '' || ! $this->stdin_open) return;

        // @: a dead server is a broken pipe, which the error handler would otherwise throw from here
        $written = @fwrite($this->stdin, $this->outbound);

        if($written === false)
        {
            $this->outbound = '';
            $this->closeStdin();
            ($this->on_end)('The server closed its stdin.');

            return;
        }

        $this->outbound = substr($this->outbound, $written);
    }
}
