---
okf_version: "0.2"
---

# semiconductor/stdio

* [Transport](architecture/transport.md) - What this package owns (the two ends of the pipe) and what it leaves to superconductor/mcp.

# Server

* [Sketch](server/sketch.md) - `rocket mcp:cli <server>`: the pipe on the loop, one frame per batch of mail, the start node's Flow, how it stops.
* [Lifecycle](server/lifecycle.md) - Idle, Handshake, Available; the timers on the bag; server pings with their own ids; notifications through the config map.

# Client

* [Connection](client/connection.md) - `StdioConnection`: spawn, wakes, stderr tail, delivery straight to the client, the timer-driven close.

# Reference

* [Config](reference/config.md) - Every key in `config/stdio.php` and who reads it.
* [Log](log.md) - Changes that altered behaviour.
