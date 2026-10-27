---
type: Server
title: Sketch
description: rocket mcp:cli <server>: the pipe on the loop, one frame per batch of mail, the start node's Flow, how it stops.
resource: src/Runner/Sketches/STDIOServerSketch.php
tags: [mcp, stdio, sketch, frame]
status: draft
generated: { by: claude-fable/5.1, at: 2026-10-26T04:30:00Z }
sources:
  - id: sketch
    resource: src/Runner/Sketches/STDIOServerSketch.php
    title: STDIOServerSketch
  - id: node
    resource: src/Workflows/STDIOLoopStartNode.php
    title: STDIOLoopStartNode
  - id: pipe
    resource: src/Pipes/StdioPipe.php
    title: StdioPipe
---

# Boot

`#[Sketch('mcp:cli {server : The name of the MCP server to start}')]`. `boot()` routes PHP errors to stderr, puts a `StdioPipe(STDIN, STDOUT)` on the loop as `mcp.stdio`, pulls the named server from the `ServerRepository` (an unknown name throws before the first frame), puts it on the `STDIOBag`, registers it in the container under its class and `MCPServer::class`, and copies `stdio.notifications` onto the bag.

# A frame

The pipe reads on `Readable` wakes, splits lines, decodes each with `LineDecoder` and posts the result as loop mail (a batch line as one `BatchReceived`, opened or refused by the node per the session's version); EOF on stdin or a failed write posts `PipeClosed` once. The sketch runner hands the frame's mail to `loop($mail)`, which runs a `Flow` over `STDIOLoopStartNode` and then `flushOutbox()`. Latency is therefore one frame: `SKETCH_REFRESH_RATE` 60 gives 16.7 ms, 1000 gives about 1.4 ms round trip.

The node, per message:

- `MCPRequest`: `their_ids->receive()` else `InvalidRequest`; log it; `state->canProcess()` else `InvalidRequest` "Method [x] is not allowed."; mark in flight; build the `ProgressReporter` and register it; run the procedure, in a fiber when `runsAsync()`; `respond()`: drop the response if cancelled meanwhile, else send it, `updateState()` on an `InitializeResponse`, flush the outbox.
- `MCPNotification`: log it; the Action from the map, if any, with the bag and the notification; a state change goes through `stateChanged()`.
- `ReceivedResponse`: a pong for a pending server ping clears misses; anything else ignored.
- `PingDue`: `sendPing()`.
- `JsonRpcError` from the decoder: sent when it `replies()`.
- `PipeClosed`, `HandshakeTimedOut`, `ClientUnresponsive`: returned, and the sketch returns `STOP`.

# Shutdown

`shutdown()` forgets the pipe from the loop, sets the streams back to blocking, writes what is still queued, and cancels the bag's timers.
