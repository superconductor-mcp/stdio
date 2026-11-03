---
type: Transport
title: Connecting a client over stdio
description: How a client launches a stdio server, agrees on a version, talks to it, and shuts it down.
resource: src/StdioClientConnection.php
tags: [client, stdio, handshake, discover, shutdown]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: 2026-11-03T18:00:00Z }
sources:
  - id: connection
    resource: src/StdioClientConnection.php
    title: StdioClientConnection
  - id: pipe
    resource: src/Pipes/StdioClientPipe.php
    title: StdioClientPipe
  - id: definition
    resource: src/ServerDefinitions/StdioServerDefinition.php
    title: StdioServerDefinition
  - id: tests
    resource: tests/Feature/ClientConnectionTest.php
    title: Client connection tests
  - id: stdio-spec
    resource: https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/stdio
    title: MCP 2026-07-28 stdio transport
  - id: versioning-spec
    resource: https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning
    title: MCP 2026-07-28 versioning and compatibility
---

# Connecting

A `StdioServerDefinition` holds the server's command, arguments and extra environment; listed in core's `mcp.mcp_servers`, it is one of the servers a client can connect to. `connect($client_configuration)` returns an opened `StdioClientConnection`.[^definition] It launches the server with `proc_open` and registers a `StdioClientPipe` on the loop: lines go to the server's stdin, lines come from its stdout, and its stderr is drained and dropped, which the transport allows.[^pipe][^stdio-spec]

# Handshake

The handshake runs in this connection, as the stdio transport describes it, never in the entity and never in core, which holds only the `ClientConnection` contract this class implements. `MCPClient` is built only after the handshake, in the agreed version.[^connection]

| Client supports | Server does | Outcome |
|---|---|---|
| 2026-07-28 | answers `server/discover` listing 2026-07-28 | 2026-07-28, no `initialize` |
| 2026-07-28 | answers with a modern error (-32020, -32021, -32022) | fails, naming the versions it supports; no fallback |
| 2026-07-28 and a handshake version | any other error, or silence until `mcp.clients.initialize_timeout` | falls back to `initialize` |
| only 2026-07-28 | is legacy | fails; nothing else is sent |
| no 2026-07-28 | — | `initialize` straight away |

`initialize` carries the newest handshake version the client supports. An answer in a version the client doesn't support fails the connection without `notifications/initialized`, as the lifecycle asks. Pings the server sends before initialized are answered; anything else it sends meanwhile, a logging notification say, waits and reaches the client first thing.[^connection][^versioning-spec][^tests] `server` holds what the server said about itself: its `InitializeResult`, or its `DiscoverResult`. `failure()` says why a connection has no client.[^connection]

# Talking

Lines read after the handshake go to `MCPClient::receiveMessage()`. Its replies, and every request the application queued on it, go out before the loop next waits. Answers from async work arrive as `OutboundMessage` mail at whatever handles the loop's mail; it passes them to `deliver()`. A started 2025-03-26 session answers a batch's requests as one batch; other versions refuse batches. On 2026-07-28 the client writes no responses, so a malformed line gets none.[^connection][^stdio-spec]

# Shutdown and restarts

`close()` closes the server's stdin and gives it two seconds to exit, then sends SIGTERM and waits again, then SIGKILL, as the transport asks.[^connection][^stdio-spec][^tests] A 2026-07-28 server that exits on its own is launched again, keeping the same client; the requests it was answering are answered with an error saying so, since the new process never saw them. Any other server exiting fails the connection: its client is gone, every request in flight gets the reason as its error, `failure()` says it, and `ConnectionFailed` fires.[^connection][^stdio-spec][^tests]

[^connection]: StdioClientConnection
[^pipe]: StdioClientPipe
[^definition]: StdioServerDefinition
[^tests]: Client connection tests
[^stdio-spec]: MCP 2026-07-28 stdio transport
[^versioning-spec]: MCP 2026-07-28 versioning and compatibility
