<?php

use Voyager\Workflows\Flow;
use Voyager\Contracts\IOPools\Loop;
use Voyager\IOPools\Waiter\Wakes\Readable;
use SuperconductorMCP\STDIO\STDIOBag;
use SuperconductorMCP\STDIO\Events\PingDue;
use SuperconductorMCP\STDIO\Pipes\StdioPipe;
use SuperconductorMCP\STDIO\Events\PipeClosed;
use SuperconductorMCP\STDIO\Events\BatchReceived;
use Fabricate\NutsAndBolts\ToolDefinition;
use Fabricate\NutsAndBolts\Schema\TextProperty;
use Fabricate\NutsAndBolts\Schema\StringProperty;
use Fabricate\NutsAndBolts\Schema\ObjectSchema;
use Fabricate\Mechanism\Session\Elicitation;
use SuperconductorMCP\STDIO\Workflows\STDIOLoopStartNode;
use Fabricate\Mechanism\Session\Lifecycle\IdleState;
use Fabricate\Mechanism\Session\Lifecycle\HandshakeState;
use Fabricate\Mechanism\Session\Lifecycle\AvailableState;
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

    // what the client writes, read by the pipe on a Readable wake; returns the mail the pipe posted
    public function feed(string $line): array
    {
        fwrite($this->client_in, $line."\n");
        $this->pipe->woke([new Readable($this->client_in)]);

        return $this->loop->registry->mail();
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

it('takes a 2025-03-26 batch line as one message per element and answers each on its own line', function () {
    $bench = bench();
    $bench->frame($bench->feed(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'c', 'version' => '1']]])));
    expect($bench->wrote()[0]['result']['protocolVersion'])->toBe('2025-03-26');

    $mail = $bench->feed(json_encode([
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
        ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'],
    ]));
    expect($mail)->toHaveCount(1)->and($mail[0])->toBeInstanceOf(BatchReceived::class)->and($mail[0]->messages)->toHaveCount(3);

    $bench->frame($mail);
    $wrote = $bench->wrote();
    expect($bench->bag->state)->toBeInstanceOf(AvailableState::class)
        ->and(array_column($wrote, 'id'))->toBe([2, 3])
        ->and($wrote[1]['result']['tools'])->toBe([]);

    // an empty batch is one InvalidRequest with no id
    $bench->frame($bench->feed('[]'));
    expect($bench->wrote()[0]['error']['code'])->toBe(-32600);
});

it('refuses a batch whole from a 2025-06-18 session', function () {
    $bench = bench();
    $bench->frame($bench->feed(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'c', 'version' => '1']]])));
    $bench->frame($bench->feed('{"jsonrpc":"2.0","method":"notifications/initialized"}'));
    $bench->wrote();

    $bench->frame($bench->feed(json_encode([['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'], ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping']])));
    $wrote = $bench->wrote();

    expect($wrote)->toHaveCount(1)->and($wrote[0]['id'])->toBeNull()->and($wrote[0]['error']['code'])->toBe(-32600)->and($wrote[0]['error']['data'])->toContain('2025-06-18');
});

it('asks the client through the pipe and takes its answer off stdin', function () {
    $bench = bench();
    $bench->bag->server_configuration = new class extends MCPServer {
        protected array $entity_info = ['name' => 'bench', 'version' => '1'];
        protected array $entity_capabilities = ['tools' => []];

        public function tools(): array
        {
            return [new ToolDefinition('greet', action: function (): TextProperty {
                $answer = app(Elicitation::class)->ask('Name?', new ObjectSchema(['name' => new StringProperty()], ['name']));

                return new TextProperty("hello {$answer->content['name']}");
            })];
        }
    };
    $bench->frame($bench->feed(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => ['elicitation' => (object) []], 'clientInfo' => ['name' => 'c', 'version' => '1']]])));
    $bench->frame($bench->feed('{"jsonrpc":"2.0","method":"notifications/initialized"}'));
    $bench->wrote();

    $bench->frame($bench->feed('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"greet","arguments":{}}}'));
    $asked = [];
    $bench->loop->until(function () use ($bench, &$asked): bool { array_push($asked, ...$bench->wrote()); return $asked !== []; });
    expect($asked[0]['method'])->toBe('elicitation/create');

    $bench->frame($bench->feed(json_encode(['jsonrpc' => '2.0', 'id' => $asked[0]['id'], 'result' => ['action' => 'accept', 'content' => ['name' => 'Ada']]])));
    $bench->loop->until(function () use ($bench): bool { return ! isset($bench->bag->in_flight[2]); });

    expect($bench->wrote()[0]['result']['content'][0]['text'])->toBe('hello Ada');
});
