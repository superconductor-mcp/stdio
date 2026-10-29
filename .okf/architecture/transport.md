---
type: Architecture
title: Transport
description: What this package owns (the two ends of the pipe) and what it leaves to superconductor/core.
resource: src
tags: [mcp, stdio, transport]
status: draft
generated: { by: claude-sonnet-5-5/claude-code, at: 2026-10-28T23:00:00Z }
sources:
  - id: provider
    resource: src/Providers/STDIOServiceProvider.php
    title: STDIOServiceProvider
  - id: spec
    resource: https://modelcontextprotocol.io/specification/2024-11-05/basic/transports
    title: Transports, 2024-11-05
---

# Lines, not protocol

MCP's stdio transport is newline-delimited JSON-RPC on a child process's stdin and stdout. This package moves those lines and runs the lifecycle around them; what a line means is superconductor/core's (`LineDecoder`, the procedures, the client node).

A line may be a 2025-03-26 batch. Both ends call `LineDecoder::decode()` and get a list: the server pipe posts one mail per element, in order, and answers each request on its own line; the client connection hands the whole list to its client. Neither end writes a batch.

# What the provider wires

- merges `config/stdio.php` and publishes it as `stdio-config`;
- pushes `STDIOServerSketch` onto `sketches.load`, so `rocket mcp:cli <server>` exists;
- registers the `stdio` creator on `ClientManager`: a new `MCPClient` with a `StdioConnection` attached, built from the `mcp_servers` entry and returned as the `Contracts\Client\MCPClient`. `StdioConnection` holds the concrete client, since it calls `receive()` and `connectionClosed()`, which the contract does not declare.

# Two ends

| End | Class | Loop resource | Reads | Writes |
|---|---|---|---|---|
| server | `StdioPipe` in `STDIOServerSketch` | `mcp.stdio` | the process's own `STDIN` | its own `STDOUT`; PHP errors go to stderr |
| client | `StdioConnection` | `mcp.client.<name>` | the child's stdout and stderr | the child's stdin |

Both are `WakeSource`s: `Readable` on what they read, `Writable` on what they write only while bytes are waiting (stdout and a pipe's stdin are almost always writable, so watching them idle would spin the loop).
