<?php

use Fabricate\Contracts\Mechanism\Attributes\ServerConfig;
use Fabricate\Contracts\Mechanism\Attributes\ToolCall;
use Fabricate\Core\Routing\ProcedureRouter;
use Fabricate\Mechanism\MessageDecoder;
use Fabricate\Mechanism\Tool;
use Fabricate\Server\ServerCapabilitiesFactory;
use Fabricate\Server\ServerConfiguration;
use Fabricate\Server\ToolRegistry;
use SuperconductorMCP\STDIO\Concerns\StatefulServerFunctionality;
use SuperconductorMCP\STDIO\Concerns\StatelessServerFunctionality;
use SuperconductorMCP\STDIO\Events\PipeClosed;
use SuperconductorMCP\STDIO\Pipes\StdioServerPipe;
use Voyager\Config\Repository;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Signals\SignalDispatcher;
use Voyager\Vessel\ControlPanel;

#[ToolCall('repeat', 'Says it back.')]
class RepeatTool extends Tool
{
    public function __construct(public readonly string $message) {}

    public function handle(): string
    {
        return $this->message;
    }
}

#[ServerConfig('piped', 'Served over stdio.', '1.0.0')]
class PipedServer extends ServerConfiguration
{
    protected array $supported_protocol_versions = ['2025-11-25', '2026-07-28'];
    protected bool|array $tools = ['listChanged' => true];

    public function tools(mixed ...$params): array { return ['repeat']; }
}

/**
 * Drives a stdio server trait turn by turn: what it sends is kept, and mail it posts waits for the next turn.
 */
abstract class PipeHarness
{
    protected ?ServerConfiguration $server_configuration;

    public function __construct(ServerConfiguration $configuration, StdioServerPipe $pipe)
    {
        $this->server_configuration = $configuration;
        $this->pipe = $pipe;
        $this->setupEmergencyStop();
    }

    public function turn(array $mail): void
    {
        $this->work($mail);
    }

    public function running(): bool
    {
        return $this->shouldContinue();
    }
}

final class StatelessHarness extends PipeHarness
{
    use StatelessServerFunctionality;

    public function __construct(ServerConfiguration $configuration, StdioServerPipe $pipe)
    {
        parent::__construct($configuration, $pipe);
        $this->startServer();
    }
}

final class StatefulHarness extends PipeHarness
{
    use StatefulServerFunctionality;
}

beforeEach(function () {
    ControlPanel::setInstance($panel = new ControlPanel());
    $panel->registerInstance('config', new Repository(['mcp' => require __DIR__.'/../../../core/config/mcp.php']));
    $panel->registerSingleton(ProcedureRouter::class);
    $panel->registerInstance('signals', new SignalDispatcher($panel));
    $panel->registerInstance(ToolRegistry::class, $tools = new ToolRegistry($panel));
    $tools->register(RepeatTool::class);

    $this->posted = new ArrayObject();
    $loop = $this->createMock(Loop::class);
    $loop->method('post')->willReturnCallback(fn(object $mail) => $this->posted->append($mail));
    $panel->registerInstance(Loop::class, $loop);

    require __DIR__.'/../../../core/routes/mcp.php';

    $this->sent = new ArrayObject();
    $this->pipe = $this->createMock(StdioServerPipe::class);
    $this->pipe->method('send')->willReturnCallback(fn(string $line) => $this->sent->append(json_decode($line, true)));

    $this->configuration = new PipedServer(new ServerCapabilitiesFactory());
});

afterEach(fn() => ControlPanel::setInstance(null));

/**
 * The next turn: everything posted since the last one, as the loop delivers it.
 */
function nextTurn(PipeHarness $harness): void
{
    [$mail] = [test()->posted->getArrayCopy(), test()->posted->exchangeArray([])];
    $harness->turn($mail);
}

it('on 2026-07-28, cancels and answers open subscriptions before it stops when stdin closes.', function () {
    $server = new StatelessHarness($this->configuration, $this->pipe);

    $server->turn([MessageDecoder::fromWire('{"jsonrpc":"2.0","id":7,"method":"subscriptions/listen","params":{"notifications":{"toolsListChanged":true},"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}')]);
    nextTurn($server);
    $server->turn([new PipeClosed()]);

    expect($server->running())->toBeTrue();

    nextTurn($server);

    expect(array_map(fn(array $message) => $message['method'] ?? $message['result']['resultType'], $this->sent->getArrayCopy()))->toBe(['notifications/subscriptions/acknowledged', 'notifications/cancelled', 'complete'])
        ->and($server->running())->toBeFalse();
});

it('in a session, hands what arrives with notifications/initialized to the started server.', function () {
    $server = new StatefulHarness($this->configuration, $this->pipe);

    $server->turn([MessageDecoder::fromWire('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"c","version":"1"}}}')]);
    $server->turn([
        MessageDecoder::fromWire('{"jsonrpc":"2.0","method":"notifications/initialized"}'),
        MessageDecoder::fromWire('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"repeat","arguments":{"message":"same read"}}}'),
    ]);

    expect($this->sent[1]['id'])->toBe(2)
        ->and($this->sent[1]['result']['content'][0]['text'])->toBe('same read');
});
