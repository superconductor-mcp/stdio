<?php

namespace SuperconductorMCP\STDIO\Pipes;

use Fabricate\Mechanism\Decoding\DecodedBatch;
use Fabricate\Mechanism\Decoding\InvalidMessage;
use Fabricate\Mechanism\MessageDecoder;
use JsonException;
use ReflectionException;
use SuperconductorMCP\STDIO\Events\BatchReceived;
use Voyager\Contracts\IOPools\WakeReason;
use Voyager\IOPools\Resources\WakeSource;
use Voyager\IOPools\Waiter\Wakes\Readable;
use Voyager\IOPools\Waiter\Wakes\Writable;
use SuperconductorMCP\STDIO\Events\PipeClosed;
use Voyager\Contracts\IOPools\Loop as LoopInterface;

class StdioServerPipe extends WakeSource
{
    public readonly string $name;

    private readonly int $read_chunk_size;

    private string $inbound = '';

    private string $outbound = '';

    // each direction ends on its own: a client can close its side of stdin and still read our replies
    private bool $input_closed = false;

    private bool $output_closed = false;

    private bool $announced_close = false;

    /**
     * @param resource $in
     * @param resource $out
     * @throws ReflectionException
     */
    public function __construct(
        protected readonly mixed $in,
        protected readonly mixed $out,
        protected readonly LoopInterface $event_loop,
    ) {
        $this->name = config('stdio.server_pipe_name');
        $this->read_chunk_size = config('stdio.read_chunk_size');

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

    public function release(): void
    {
        stream_set_blocking($this->in, true);
        stream_set_blocking($this->out, true);

        // replies queued in the last frame still go out; blocking now, so each write waits for the pipe
        while ($this->outbound !== '' && ! $this->output_closed) {
            $this->flush();
        }
    }

    /**
     * @throws ReflectionException
     * @throws JsonException
     */
    private function read(): void
    {
        while (($chunk = fread($this->in, $this->read_chunk_size)) !== false && $chunk !== '') {
            $this->inbound .= $chunk;
        }

        while (($at = strpos($this->inbound, "\n")) !== false) {
            $line = rtrim(substr($this->inbound, 0, $at), "\r");
            $this->inbound = substr($this->inbound, $at + 1);

            if ($line !== '') {
                $messages = MessageDecoder::fromWire($line);

                if($messages instanceof InvalidMessage)
                {
                    $this->send($messages->toJson());
                }
                else
                {
                    $this->event_loop->post($messages instanceof DecodedBatch ? new BatchReceived($messages) : $messages);
                }
            }
        }

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

    private function close(): void
    {
        if ($this->announced_close) {
            return;
        }

        $this->announced_close = true;
        $this->event_loop->post(new PipeClosed);
    }
}
