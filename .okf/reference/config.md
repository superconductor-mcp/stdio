---
type: Reference
title: Config
description: Every key in config/stdio.php and who reads it.
resource: config/stdio.php
tags: [mcp, stdio, config]
status: draft
generated: { by: claude-fable/5.1, at: 2026-10-26T04:30:00Z }
sources:
  - id: config
    resource: config/stdio.php
    title: config/stdio.php
---

| Key | Default | Read by |
|---|---|---|
| `handshake.stop_waiting.enabled` / `.ttl` | `true` / `60` | `STDIOLoopStartNode::stateChanged()`: the Handshake deadline |
| `ping_time.enabled` / `.ttl` / `.max_misses` | `true` / `10` / `3` | `stateChanged()` arms the interval; `sendPing()` counts misses |
| `notifications` | `initialized` → `InitializedAction`, `cancelled` → `CancelledAction` | copied onto the bag at boot; `processNotification()` |
| `client.shutdown_grace` | `2` | `StdioConnection::close()`: each of the two waits |

The connect deadline is not here: it belongs to the client, `mcp.client.connect_timeout` in superconductor/mcp.

A published copy (`vendor:publish --tag=stdio-config`) overrides the merged defaults entirely, so a key added here is added to every published copy too.
