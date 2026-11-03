---
type: Package
title: superconductor/stdio
description: The stdio transport - mcp:cli-legacy for the stateful versions, mcp:cli for 2026-07-28.
resource: composer.json
tags: [package, orientation, status, transport, stdio]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: 2026-11-03T00:00:00Z }
sources:
  - id: manifest
    resource: composer.json
    title: Package manifest
---

# Overview

The stdio transport for Superconductor MCP, version 0.11.0, requiring `superconductor/core`, `venusian-voyager/io-pools` and `venusian-voyager/sketches`.[^manifest]

# State

Two sketches, registered by `STDIOServiceProvider`:[^manifest]

* `mcp:cli-legacy {server}` runs the stateful versions, 2024-11-05 through 2025-11-25: its own initialize handshake, then an `MCPServer` in the negotiated version; batches in a started 2025-03-26 session.
* `mcp:cli {server}` runs 2026-07-28 (`StatelessServerFunctionality`): no handshake, one `MCPServer` answering each request on its own; everything goes out on stdout oldest first, async answers arriving as `OutboundMessage` mail. Batches are refused with -32600, and closing stdin ends it.

[^manifest]: Package manifest
