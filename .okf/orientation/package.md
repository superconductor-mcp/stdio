---
type: Package
title: superconductor/stdio
description: The stdio transport - mcp:cli-legacy for the stateful versions, mcp:cli for 2026-07-28.
resource: composer.json
tags: [package, orientation, status, transport, stdio]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: 2026-11-03T12:00:00Z }
sources:
  - id: manifest
    resource: composer.json
    title: Package manifest
---

# Overview

The stdio transport for Superconductor MCP, version 0.11.0, requiring `superconductor/core` and the `venusian-voyager` `contracts`, `io-pools`, `nuts-and-bolts`, `signals` and `sketches` components.[^manifest] Its sketches extend `Voyager\Sketches\Sketch`. Tests run with `vendor/bin/pest` from the package root, reading core's config and routes from where Composer installed it; CI runs them on PHP 8.4 and 8.5, NTS and ZTS. User documentation: https://venusian.projectsaturnstudios.com/docs/superconductor/stdio.

# State

Two sketches, registered by `STDIOServiceProvider`:[^manifest]

* `mcp:cli-legacy {server}` runs the stateful versions, 2024-11-05 through 2025-11-25: its own initialize handshake, then an `MCPServer` in the negotiated version; whatever arrives in the same read as `notifications/initialized` goes to the started server; batches in a started 2025-03-26 session.
* `mcp:cli {server}` runs 2026-07-28 (`StatelessServerFunctionality`): no handshake, one `MCPServer` answering each request on its own; everything goes out on stdout oldest first, async answers arriving as `OutboundMessage` mail. Batches are refused with -32600. Closing stdin closes the server, which cancels and answers each open subscription as mail, then posts `ServerClosed`; mail arrives in order, so the process ends once those are on stdout.

Clients connect to stdio servers through `StdioServerDefinition`; see [Connecting a client over stdio](/transport/client.md).

[^manifest]: Package manifest
