<?php

namespace SuperconductorMCP\STDIO\Client;

use RuntimeException;
use Fabricate\Client\MCPClient;
use Fabricate\NutsAndBolts\LineDecoder;
use Fabricate\Contracts\Client\Connection;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Wake;
use Voyager\Contracts\IOPools\WakeReason;
use Voyager\IOPools\Resources\WakeSource;
use Voyager\IOPools\Waiter\Wakes\Readable;
use Voyager\IOPools\Waiter\Wakes\Writable;
use Voyager\IOPools\Waiter\Wakes\ProcessExit;
use Voyager\Contracts\IOPools\LoopResources\Timer;

// the client end of stdio: the server runs as a child process and this is its pipes, joined to the host's event loop
class StdioConnection extends WakeSource implements Connection
{
    public const string PREFIX = 'mcp.client.';

    private const int CHUNK = 65536;

    private const int STDERR_TAIL = 2048;

    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    /** @var resource|null */
    private $stderr = null;

    private ?int $pid = null;

    private string $inbound = '';

    private string $outbound = '';

    // the last of what the server wrote to stderr: a server that dies on start usually only says why there
    private string $stderr_tail = '';

    private bool $open = false;

    // close() has begun: stdin is shut and the server is being waited on, escalating on a timer
    private bool $closing = false;

    private bool $released = false;

    private ?Timer $escalation = null;

    private bool $stderr_open = false;

    /**
     * @param array{command: string, args?: list<string>, env?: array<string, string>, cwd?: string} $definition
     */
    public function __construct(
        private readonly array $definition,
        private readonly Loop $event_loop,
        private readonly MCPClient $client,
    ) {}

    public function open(): void
    {
        $command = $this->command();

        // a command that is not there is said so by name, before anything is spawned
        if (is_null($this->executable($command[0]))) {
            throw new RuntimeException("could not start: [{$command[0]}] is not an executable on this machine's PATH.");
        }

        // the child keeps the host's environment, with the entry's own variables on top
        $env = isset($this->definition['env'])
            ? [...getenv(), ...array_map(strval(...), $this->definition['env'])]
            : null;

        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $this->definition['cwd'] ?? null, $env);

        if (! is_resource($process)) {
            throw new RuntimeException('could not start: '.implode(' ', $command));
        }

        [$this->stdin, $this->stdout, $this->stderr] = $pipes;

        // PHP's own read buffer can hold bytes the fd no longer reports as readable; skip it
        stream_set_read_buffer($this->stdout, 0);
        stream_set_write_buffer($this->stdin, 0);
        stream_set_blocking($this->stdin, false);
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);

        $this->process = $process;
        $this->pid = proc_get_status($process)['pid'];
        $this->open = true;
        $this->stderr_open = true;

        $this->event_loop->resource(self::PREFIX.$this->client->name(), $this);
    }

    public function send(string $line): void
    {
        if (! $this->open || $this->closing) {
            return;
        }

        $this->outbound .= $line."\n";

        // write now; the Writable wake only carries whatever the pipe refused
        $this->flush();
    }

    // fully ended: pipes closed and the process reaped
    public function closed(): bool
    {
        return $this->released || ! $this->open;
    }

    public function describe(): string
    {
        return implode(' ', $this->command());
    }

    public function wakes(): array
    {
        if (! $this->open) {
            return [];
        }

        $wakes = [new Readable($this->stdout)];

        if ($this->stderr_open) {
            $wakes[] = new Readable($this->stderr);
        }

        // stdin is almost always writable, so it is watched only while bytes are waiting
        if ($this->outbound !== '' && ! $this->closing) {
            $wakes[] = new Writable($this->stdin);
        }

        if ($this->event_loop->supports(WakeReason::PROCESS_EXIT)) {
            $wakes[] = new ProcessExit($this->pid);
        }

        return $wakes;
    }

    public function woke(array $fired): void
    {
        if (! $this->open) {
            return;
        }

        $this->drainStderr();

        if (array_any($fired, fn (Wake $wake): bool => $wake->kind() === WakeReason::WRITEABLE)) {
            $this->flush();
        }

        $exited = array_any($fired, fn (Wake $wake): bool => $wake instanceof ProcessExit);

        // an exit notice can beat the child's last bytes: once it has exited, read the pipe to its end
        if ($exited) {
            stream_set_blocking($this->stdout, true);
            $chunk = stream_get_contents($this->stdout);
            $this->inbound .= $chunk === false ? '' : $chunk;
        } else {
            while (($chunk = fread($this->stdout, self::CHUNK)) !== false && $chunk !== '') {
                $this->inbound .= $chunk;
            }
        }

        $messages = [];

        while (($at = strpos($this->inbound, "\n")) !== false) {
            $line = rtrim(substr($this->inbound, 0, $at), "\r");
            $this->inbound = substr($this->inbound, $at + 1);

            if ($line !== '') {
                $messages[] = LineDecoder::decodeString($line);
            }
        }

        // the last replies land before the client hears that the server is gone
        if ($messages !== []) {
            $this->client->receive($messages);
        }

        if ($this->open && ($exited || feof($this->stdout))) {
            $this->drainStderr();

            // during a close the exit is the expected end; otherwise the server went away on its own
            $this->closing ? $this->finish() : $this->ended();
        }
    }

    // the spec's order: close stdin, wait, SIGTERM, wait, SIGKILL; each wait is stdio.client.shutdown_grace seconds
    // on loop timers, so several connections close side by side and the loop keeps turning meanwhile
    public function close(): void
    {
        if (! $this->open || $this->closing) {
            return;
        }

        $this->closing = true;
        $this->outbound = '';

        if (is_resource($this->stdin)) {
            fclose($this->stdin);
        }

        $grace = (float) config('stdio.client.shutdown_grace', 2);

        $this->escalation = $this->event_loop->at($grace, function () use ($grace): void {
            if (! $this->running()) {
                $this->finish();

                return;
            }

            proc_terminate($this->process, SIGTERM);

            $this->escalation = $this->event_loop->at($grace, function (): void {
                if ($this->running()) {
                    proc_terminate($this->process, SIGKILL);
                }

                $this->finish();
            });
        });
    }

    // the close ran its course, by exit or by escalation
    private function finish(): void
    {
        $this->leave();
        $this->release();
    }

    private function running(): bool
    {
        return is_resource($this->process) && proc_get_status($this->process)['running'];
    }

    // the server went away on its own; tidy up and tell the client why
    private function ended(): void
    {
        $this->leave();
        $status = proc_get_status($this->process);
        $this->release();

        $code = $status['running'] ? '' : " (exit code {$status['exitcode']})";
        $tail = trim($this->stderr_tail);

        $this->client->connectionClosed('the server exited'.$code.'.'.($tail === '' ? '' : " Last output: {$tail}"));
    }

    // out of the loop first, so no wake arrives while the pipes are being closed
    private function leave(): void
    {
        $this->open = false;
        $this->event_loop->forget(self::PREFIX.$this->client->name());
    }

    private function release(): void
    {
        $this->escalation?->cancel();
        $this->escalation = null;
        $this->released = true;

        foreach ([$this->stdin, $this->stdout, $this->stderr] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($this->process)) {
            proc_close($this->process);
        }

        $this->outbound = '';
        $this->stderr_open = false;
    }

    private function flush(): void
    {
        if ($this->outbound === '' || ! $this->open || $this->closing) {
            return;
        }

        $written = @fwrite($this->stdin, $this->outbound);

        if ($written === false) {
            // the server closed its stdin: it is on its way out, and the stdout EOF will say so
            $this->outbound = '';

            return;
        }

        $this->outbound = substr($this->outbound, $written);
    }

    private function drainStderr(): void
    {
        if (! $this->stderr_open) {
            return;
        }

        $chunk = fread($this->stderr, self::CHUNK);
        $this->stderr_tail = substr($this->stderr_tail.($chunk === false ? '' : $chunk), -self::STDERR_TAIL);

        if (feof($this->stderr)) {
            $this->stderr_open = false;
        }
    }

    // the path the shell would run for this command, or null when there is none
    private function executable(string $command): ?string
    {
        if (str_contains($command, '/')) {
            return is_executable($command) ? $command : null;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_executable($dir.'/'.$command)) {
                return $dir.'/'.$command;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function command(): array
    {
        return [(string) $this->definition['command'], ...array_map(strval(...), $this->definition['args'] ?? [])];
    }
}
