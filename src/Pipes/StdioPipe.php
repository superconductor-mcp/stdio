<?php

namespace SuperconductorMCP\STDIO\Pipes;

use Fabricate\NutsAndBolts\LineDecoder;
use SuperconductorMCP\STDIO\Events\InboundLine;
use SuperconductorMCP\STDIO\Events\PipeClosed;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\WakeReason;
use Voyager\IOPools\Resources\WakeSource;
use Voyager\IOPools\Waiter\Wakes\Readable;
use Voyager\IOPools\Waiter\Wakes\Writable;

class StdioPipe extends WakeSource
{
    public const string NAME = 'mcp.stdio';

    private const int CHUNK = 65536;

    private string $inbound = '';

    private string $outbound = '';

    // each direction ends on its own: a client can close its side of stdin and still read our replies
    private bool $input_closed = false;

    private bool $output_closed = false;

    private bool $announced_close = false;

    /**
     * @param resource $in
     * @param resource $out
     */
    public function __construct(
        private readonly mixed $in,
        private readonly mixed $out,
        private readonly Loop $event_loop,
    ) {
        // PHP's own read buffer can hold bytes the fd no longer reports as readable; skip it
        stream_set_read_buffer($this->in, 0);
        stream_set_write_buffer($this->out, 0);
        stream_set_blocking($this->in, false);
        stream_set_blocking($this->out, false);
    }

    public function closed(): bool
    {
        return $this->input_closed || $this->output_closed;
    }

    public function send(string $line): void
    {
        $this->outbound .= $line."\n";

        // write now; the Writable wake only carries whatever the pipe refused
        $this->flush();
    }

    public function wakes(): array
    {
        $wakes = $this->input_closed ? [] : [new Readable($this->in)];

        // stdout is almost always writable, so it is watched only while bytes are waiting
        if ($this->outbound !== '' && ! $this->output_closed) {
            $wakes[] = new Writable($this->out);
        }

        return $wakes;
    }

    public function woke(array $fired): void
    {
        foreach ($fired as $wake) {
            match ($wake->kind()) {
                WakeReason::READABLE => $this->read(),
                WakeReason::WRITEABLE => $this->flush(),
                default => null,
            };
        }
    }

    // the blocking flag lives on the descriptor the parent shell shares; hand it back as found
    public function release(): void
    {
        stream_set_blocking($this->in, true);
        stream_set_blocking($this->out, true);

        // replies queued in the last frame still go out; blocking now, so each write waits for the pipe
        while ($this->outbound !== '' && ! $this->output_closed) {
            $this->flush();
        }
    }

    private function read(): void
    {
        while (($chunk = fread($this->in, self::CHUNK)) !== false && $chunk !== '') {
            $this->inbound .= $chunk;
        }

        while (($at = strpos($this->inbound, "\n")) !== false) {
            $line = rtrim(substr($this->inbound, 0, $at), "\r");
            $this->inbound = substr($this->inbound, $at + 1);

            if ($line !== '') {
                $this->event_loop->post(LineDecoder::decodeString($line));
            }
        }

        // an empty read means "nothing yet" or "client gone"; only EOF says which
        if (feof($this->in)) {
            $this->input_closed = true;
            $this->close();
        }
    }

    private function flush(): void
    {
        if ($this->outbound === '' || $this->output_closed) {
            return;
        }

        $written = fwrite($this->out, $this->outbound);

        if ($written === false) {
            $this->output_closed = true;
            $this->outbound = '';
            $this->close();

            return;
        }

        $this->outbound = substr($this->outbound, $written);
    }

    // the session ends when either direction does; the sketch hears it once
    private function close(): void
    {
        if ($this->announced_close) {
            return;
        }

        $this->announced_close = true;
        $this->event_loop->post(new PipeClosed);
    }
}