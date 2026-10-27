<div align="center" id="header">

<h1>semiconductor/stdio</h1>
<h3>The stdio transport for Superconductor MCP: serve over your own pipes, consume over theirs</h3>

[![CI](https://img.shields.io/github/actions/workflow/status/superconductor-mcp/stdio/tests.yml?style=flat-square&label=CI)](https://github.com/superconductor-mcp/stdio/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/semiconductor/stdio?style=flat-square)](https://packagist.org/packages/semiconductor/stdio)
[![PHP](https://img.shields.io/packagist/dependency-v/semiconductor/stdio/php?style=flat-square&label=PHP)](https://packagist.org/packages/semiconductor/stdio)
[![MCP](https://img.shields.io/badge/MCP-2025--03--26-5b4ee6?style=flat-square)](https://modelcontextprotocol.io/specification/2025-03-26/basic/transports)
[![License](https://img.shields.io/github/license/superconductor-mcp/stdio?style=flat-square)](LICENSE)
[![OKF](https://img.shields.io/badge/OKF-0.2-6f42c1?style=flat-square&label=knowledge%20bundle)](.okf/index.md)

</div>

MCP's stdio transport is the simplest one there is: the client starts the server as a child process, writes JSON-RPC lines to its stdin and reads them from its stdout. Every harness supports it, every SDK ships it, and a server that speaks it needs no port, no TLS and no network.

[superconductor/mcp](https://github.com/superconductor-mcp/mcp) speaks the protocol but knows no transport by name. <b>The only problem?</b> Someone has to own the pipes.

semiconductor/stdio owns both ends. On the server side it is a sketch, `rocket mcp:cli <server>`, that puts stdin and stdout on the application's event loop and runs the lifecycle around superconductor/mcp's procedures. On the client side it is a `StdioConnection` that spawns the server named in `mcp_servers`, joins its pipes to the same loop, and hands every decoded line straight to its `MCPClient`.

## Basic usage

Most ways you will interface with semiconductor/stdio are in two parts -

**Serving** a server defined with superconductor/mcp:

```bash
php rocket mcp:cli concept
```

That is the command a harness runs. In Claude Desktop, Cursor or Claude Code:

```json
{
  "mcpServers": {
    "concept": { "command": "php", "args": ["/path/to/app/rocket", "mcp:cli", "concept"] }
  }
}
```

**Consuming** another stdio server. The entry in `config/mcp.php` has the same keys; `type` defaults to `stdio` when a `command` is present.

```php
'mcp_servers' => [
    'neo4j' => ['command' => 'neo4j-mcp', 'env' => ['NEO4J_URI' => env('NEO4J_URI'), 'NEO4J_PASSWORD' => env('NEO4J_PASSWORD')]],
],
```

```php
$neo4j = app(ClientManager::class)->client('neo4j');
$neo4j->ready()->wait();
$neo4j->callTool('get-schema')->wait()->content;
```

## Requirements

| | |
|---|---|
| PHP | 8.4 or newer, with `proc_open` available. |
| VenusianPHP | A 0.10 application with `venusian-voyager/io-pools` and `sketches`. |
| superconductor/mcp | 0.10. This package adds the transport only. |
| Platform | macOS and Linux. On macOS the server sketch needs a pipe or a terminal on stdin; kqueue refuses `/dev/null`. |

## Installation

```bash
composer require superconductor/mcp semiconductor/stdio
```

The provider is discovered. It merges `config/stdio.php`, adds `mcp:cli` to the application's sketches and registers the `stdio` client creator on `mcp.clients`. Publish the config to change any of it:

```bash
php computer vendor:publish --tag=stdio-config
```

Verify with a one-line handshake:

```console
$ printf '%s\n' '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"me","version":"1"}}}' '[{"jsonrpc":"2.0","method":"notifications/initialized"},{"jsonrpc":"2.0","id":1,"method":"ping"}]' | php rocket mcp:cli concept
{"jsonrpc":"2.0","id":0,"result":{"protocolVersion":"2025-03-26","capabilities":{…},"serverInfo":{…}}}
{"jsonrpc":"2.0","id":1,"result":{}}
```

## Configuration

`config/stdio.php`:

| Key | Default | What it does |
|---|---|---|
| `handshake.stop_waiting.enabled` / `.ttl` | `true` / `60` | A client that sends `initialize` but never `notifications/initialized` is dropped after this many seconds. |
| `ping_time.enabled` / `.ttl` / `.max_misses` | `true` / `10` / `3` | The server pings its client every `ttl` seconds in every state but Idle; this many unanswered in a row stops the server. |
| `notifications` | `initialized`, `cancelled` | Method to Action class (`Fabricate\Mechanism\Session\Actions`). A notification not listed is logged and ignored. |
| `client.shutdown_grace` | `2` | Seconds a closing connection waits for the server to exit after closing its stdin, and again after `SIGTERM`, before escalating. |

The server's response latency is one sketch frame, set by `SKETCH_REFRESH_RATE` in the application's `.env` (60 Hz is 16.7 ms). A server that needs less puts a higher rate in its entry's `env`.

## The server side

`STDIOServerSketch` runs the lifecycle:

| State | Accepts | Leaves on |
|---|---|---|
| Idle | `initialize` only | the `initialize` response |
| Handshake | `initialize`, `ping`, `logging/setLevel` | `notifications/initialized`, or `stop_waiting` running out |
| Available | everything but `initialize` | the pipe closing, or `max_misses` unanswered pings |

A line may be a JSON-RPC batch; each message in it is handled in order and each request answered on its own line. A request the state refuses gets `InvalidRequest` "Method [x] is not allowed." with its id. The sketch stops when the pipe closes, the handshake times out, or the client stops answering pings. PHP warnings go to stderr so stdout carries frames only.

## The client side

`StdioConnection` is a loop `WakeSource` around the child's three pipes. It reads stdout on wakes, keeps the last 2 KiB of stderr so a server that dies on start can say why, writes stdin with a `Writable` wake only while bytes are waiting, and listens for the process exiting where the loop supports it. Decoded lines go straight to the connection's own client, never to loop mail, so two servers can both answer id 1 without crossing.

Closing follows the spec's order on loop timers, so several connections close side by side and the loop keeps turning: stdin closed, `shutdown_grace` to exit, `SIGTERM`, the same grace again, `SIGKILL`. A server that exits on its own, or a command that is not there, turns its client into `Error` with the reason, exit code and stderr tail.

## Packages you might use with this one

| Package | Gives |
|---|---|
| [superconductor/mcp](https://github.com/superconductor-mcp/mcp) | The protocol: servers, clients, messages, procedures. Required. |

## AI-assisted development

This codebase was built with LLM assistance under human direction. Prompts, reviews, and architectural decisions were owned by a professional software engineer — the model accelerates drafting and exploration; it does not replace judgment on APIs, dependency direction, or what ships.

## Agentic Documentation

This repository carries an [Open Knowledge Format](https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md) bundle at [`.okf/`](.okf/): Markdown with YAML frontmatter, readable by you and loadable by a coding agent. It records what the README doesn't: how the sketch, the pipe and the start node share a frame, what each lifecycle state refuses and why, how the server's pings and the client's answers avoid colliding ids, how the client connection reads, writes and closes without ever blocking the loop, and a log of every change that altered behaviour.

Start at [`.okf/index.md`](.okf/index.md). [`AGENTS.md`](AGENTS.md) is the short rule sheet an agent reads before touching the pipe, the sketch, the connection or the lifecycle; each rule points into the bundle for the reason behind it.

Point your agent at the repo and it has the same context the maintainer has. Point yourself at it when the README's answer is "it does X" and you want "because".

## Resources

* [MCP transports, 2025-03-26](https://modelcontextprotocol.io/specification/2025-03-26/basic/transports): the stdio rules this package follows, including the shutdown order and batches.
* [superconductor/mcp](https://github.com/superconductor-mcp/mcp): the protocol this transport carries.
* [VenusianPHP](https://github.com/VenusianPHP): sketches and the IOPools loop.
* [Open Knowledge Format](https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md): the spec the [`.okf/`](.okf/) bundle follows.

## Running the test suite

The suite is Pest v4. The connection tests run real children: a fixture server in `tests/Fixtures/echo-server.php` under the PHP that runs the tests, and `sleep` for the server that ignores a closed stdin. The start node runs on socket pairs. No test needs a terminal or a network.

```bash
git clone https://github.com/superconductor-mcp/stdio.git
cd stdio
composer install
vendor/bin/pest
```

```console
Tests:    12 passed (45 assertions)
```

## Contributing

Open an issue first for anything beyond a typo. Pull requests come with a Pest test for the behaviour and keep CI green. A protocol change goes to superconductor/mcp first; this package moves lines. Read [`AGENTS.md`](AGENTS.md) for the rules the code follows.

## Security

The server end reads and writes the streams it was started with and nothing else; the client end spawns the command its config names and kills it on close if it will not leave. See [SECURITY.md](SECURITY.md) for the model and how to report a vulnerability.

## License

MIT. See [LICENSE](LICENSE).
