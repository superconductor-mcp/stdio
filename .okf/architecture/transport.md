---
type: Architecture
title: Transport
description: What this package owns (the two ends of the pipe) and what it leaves to superconductor/mcp.
resource: src
tags: [mcp, stdio, transport]
status: draft
generated: { by: claude-fable/5.1, at: 2026-10-26T04:30:00Z }
sources:
  - id: provider
    resource: src/Providers/STDIOServiceProvider.php
    title: STDIOServiceProvider
  - id: spec
    resource: https://modelcontextprotocol.io/specification/2024-11-05/basic/transports
    title: Transports, 2024-11-05
---

# Lines, not protocol

MCP's stdio transport is newline-delimited JSON-RPC on a child process's stdin and stdout. This package moves those lines and runs the lifecycle around them; what a line means is superconductor/mcp's (`LineDecoder`, the procedures, the client node).

# What the provider wires

- merges `config/stdio.php` and publishes it as `stdio-config`;
- pushes `STDIOServerSketch` onto `sketches.load`, so `rocket mcp:cli <server>` exists;
- registers the `stdio` creator on `ClientManager`: a new `MCPClient` with a `StdioConnection` attached, built from the `mcp_servers` entry.

# Two ends

| End | Class | Loop resource | Reads | Writes |
|---|---|---|---|---|
| server | `StdioPipe` in `STDIOServerSketch` | `mcp.stdio` | the process's own `STDIN` | its own `STDOUT`; PHP errors go to stderr |
| client | `StdioConnection` | `mcp.client.<name>` | the child's stdout and stderr | the child's stdin |

Both are `WakeSource`s: `Readable` on what they read, `Writable` on what they write only while bytes are waiting (stdout and a pipe's stdin are almost always writable, so watching them idle would spin the loop).
