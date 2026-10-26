<?php

use Voyager\Workflows\Flow;
use Voyager\Contracts\IOPools\Loop;
use SuperconductorMCP\STDIO\STDIOBag;
use SuperconductorMCP\STDIO\Events\PingDue;
use SuperconductorMCP\STDIO\Pipes\StdioPipe;
use SuperconductorMCP\STDIO\Events\PipeClosed;
use SuperconductorMCP\STDIO\Workflows\STDIOLoopStartNode;
use SuperconductorMCP\STDIO\StateMachines\Lifecycle\IdleState;
use SuperconductorMCP\STDIO\StateMachines\Lifecycle\HandshakeState;
use SuperconductorMCP\STDIO\StateMachines\Lifecycle\AvailableState;
use Fabricate\Server\MCPServer;
use Fabricate\NutsAndBolts\LineDecoder;
use Fabricate\NutsAndBolts\Messages\Ping\PingRequest;
use Fabricate\NutsAndBolts\Messages\Tools\CallToolRequest;
use Fabricate\NutsAndBolts\Messages\Responses\ReceivedResponse;
use Fabricate\NutsAndBolts\Messages\Initialize\InitializeRequest;
use Fabricate\NutsAndBolts\Messages\Initialize\InitializedNotification;

// the server end of stdio on socket pairs: $in is what the client writes, $out is what the client reads
final class Bench
{
    public STDIOBag $bag;
    public StdioPipe $pipe;
    private $client_in;
    private $client_out;

    public function __construct(public readonly Loop $loop)
    {
        [$this->client_in, $server_in] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        [$server_out, $this->client_out] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($this->client_out, false);

        $this->pipe = new StdioPipe($server_in, $server_out, $loop);
        $this->bag = new STDIOBag();
        $this->bag->put('notifications', config('stdio.notifications'));
        $this->bag->server_configuration = new class extends MCPServer {
            protected array $entity_info = ['name' => 'bench', 'version' => '1'];
            protected array $entity_capabilities = ['tools' => ['listChanged' => true]];
        };
    }

    // one frame of the server: this mail through the start node
    public function frame(array $mail): ?string
    {
        return (new Flow(new STDIOLoopStartNode($mail, $this->pipe, $this->loop)))->run($this->bag);
    }

    public function line(array $message): mixed
    {
        return LineDecoder::decodeString(json_encode(['jsonrpc' => '2.0', ...$message]));
    }

    /** @return list<array> every line the server wrote since the last read */
    public function wrote(): array
    {
        $lines = [];

        while (($line = fgets($this->client_out)) !== false) {
            $lines[] = json_decode($line, true);
        }

        return $lines;
    }
}

function bench(array $stdio = []): Bench
{
    $loop = testLoop();
    bareApp($stdio, [], $loop);

    return new Bench($loop);
}

it('only accepts initialize while idle, then moves through the handshake to available', function () {
    $bench = bench();

    $bench->frame([new PingRequest(0)]);
    expect($bench->wrote()[0]['error']['data'])->toContain('not allowed')->and($bench->bag->state)->toBeInstanceOf(IdleState::class);

    $bench->frame([new InitializeRequest(1, '2024-11-05', [], ['name' => 'c', 'version' => '1'])]);
    expect($bench->wrote()[0]['result']['protocolVersion'])->toBe('2024-11-05')->and($bench->bag->state)->toBeInstanceOf(HandshakeState::class);

    $bench->frame([new InitializedNotification()]);
    expect($bench->bag->state)->toBeInstanceOf(AvailableState::class)->and($bench->wrote())->toBe([]);

    $bench->frame([new PingRequest(2)]);
    expect($bench->wrote()[0])->toBe(['jsonrpc' => '2.0', 'id' => 2, 'result' => []]);
});

it('refuses an id the client already used, without touching anything else', function () {
    $bench = bench();
    $bench->frame([new InitializeRequest(1, '2024-11-05', [], ['name' => 'c', 'version' => '1'])]);
    $bench->wrote();

    $bench->frame([new InitializeRequest(1, '2024-11-05', [], ['name' => 'c', 'version' => '1'])]);
    expect($bench->wrote()[0]['error']['data'])->toContain('already been used');
});

it('sends decoder errors as they are, and nothing at all for an unknown notification', function () {
    $bench = bench();

    $bench->frame([
        $bench->line(['method' => 'notifications/whatever']),
        $bench->line(['id' => 5, 'method' => 'logging/setLevel', 'params' => ['level' => 'loud']]),
        LineDecoder::decodeString('not json'),
    ]);

    $wrote = $bench->wrote();
    expect($wrote)->toHaveCount(2)
        ->and($wrote[0]['id'])->toBe(5)->and($wrote[0]['error']['code'])->toBe(-32602)
        ->and($wrote[1]['id'])->toBeNull()->and($wrote[1]['error']['code'])->toBe(-32700);
});

it('pings the client with its own ids and stops after too many unanswered pings', function () {
    $bench = bench(['ping_time' => ['max_misses' => 2]]);
    $bench->frame([new InitializeRequest(7, '2024-11-05', [], ['name' => 'c', 'version' => '1'])]);
    $bench->wrote();

    // the server's ping ids count on their own, so the client's 7 does not push them along
    expect($bench->frame([new PingDue()]))->toBeNull();
    expect($bench->wrote()[0])->toBe(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'ping']);

    $bench->frame([new ReceivedResponse(0, [])]);
    expect($bench->bag->pending_pings)->toBe([])->and($bench->bag->ping_misses)->toBe(0);

    $bench->frame([new PingDue()]);
    $bench->frame([new PingDue()]);
    expect($bench->bag->ping_misses)->toBe(1);
    expect($bench->frame([new PingDue()]))->toBe('client-unresponsive');
});

it('drops the response of a request the client cancelled while it ran', function () {
    $bench = bench();
    $bench->frame([new InitializeRequest(1, '2024-11-05', [], ['name' => 'c', 'version' => '1']), new InitializedNotification()]);
    $bench->wrote();

    $bench->bag->cancelled[2] = true;
    $bench->frame([new PingRequest(2)]);
    expect($bench->wrote())->toBe([])->and($bench->bag->cancelled)->toBe([]);
});

it('runs an async procedure in a fiber and answers once it finishes', function () {
    $bench = bench();
    $bench->frame([new InitializeRequest(1, '2024-11-05', [], ['name' => 'c', 'version' => '1']), new InitializedNotification()]);
    $bench->wrote();

    $bench->frame([new CallToolRequest(2, 'missing')]);
    $bench->loop->until(fn (): bool => ! isset($bench->bag->in_flight[2]));

    $wrote = $bench->wrote();
    expect($wrote)->toHaveCount(1)->and($wrote[0]['id'])->toBe(2)->and($wrote[0]['error']['code'])->toBe(-32602);
});

it('stops when the pipe closes', function () {
    $bench = bench();
    expect($bench->frame([new PipeClosed()]))->toBe('pipe-closed');
});
