---
type: Client
title: Connection
description: StdioConnection: spawn, wakes, stderr tail, delivery straight to the client, the timer-driven close.
resource: src/Client/StdioConnection.php
tags: [mcp, stdio, client, proc_open]
status: draft
generated: { by: claude-sonnet-5-5/claude-code, at: 2026-10-28T23:00:00Z }
sources:
  - id: connection
    resource: src/Client/StdioConnection.php
    title: StdioConnection
  - id: spec
    resource: https://modelcontextprotocol.io/specification/2024-11-05/basic/transports
    title: Transports, 2024-11-05
---

# Open

`open()` resolves the entry's `command` on `PATH` first (a missing one throws "could not start: [x] is not an executable on this machine's PATH." before anything is spawned), then `proc_open`s `[command, ...args]` with three pipes, the entry's `cwd`, and the application's environment with the entry's `env` on top. All three pipes are non-blocking with PHP's read buffer off; the connection joins the loop as `mcp.client.<name>`.

# Wakes

`Readable` on stdout; `Readable` on stderr while open; `Writable` on stdin only while `outbound` holds bytes and no close has begun; `ProcessExit(pid)` where the loop supports it. `woke()` drains stderr into a 2 KiB tail, flushes on a writable wake, reads stdout (to the end once the process exited, since the exit notice can beat the last bytes), splits lines, decodes each, and hands the batch to the concrete client's `receive()` (`Fabricate\Client\MCPClient`; the contract does not declare it) before anything else. Then EOF or exit: during a close that is the expected end; otherwise `ended()` releases everything and tells the client "the server exited (exit code N). Last output: <stderr tail>".

Decoded lines never go to loop mail: with several connections, two replies carrying id 1 would be indistinguishable there.

# Send

`send($line)` appends a newline and writes at once; what the pipe refuses waits for the `Writable` wake. A failed write means the server closed its stdin; the stdout EOF that follows reports it.

# Close, in the spec's order, on timers

`close()`: mark closing, drop unsent bytes, close stdin, arm `stdio.client.shutdown_grace`. When it fires and the process still runs: `SIGTERM`, arm the grace again; when that fires and it still runs: `SIGKILL`. An exit seen through the wakes meanwhile ends it early. `finish()` leaves the loop and releases the pipes and the process; `closed()` is true from then on. Nothing blocks, so `ClientManager::closeAll()` closes every server side by side in one grace, not one after another.
