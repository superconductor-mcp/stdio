# Agent guidance — semiconductor/stdio

1. **Read [`.okf/index.md`](.okf/index.md) first** before changing the pipe, the sketch, the connection or the lifecycle. Open only the concepts the change touches.
2. **This is a transport, not the protocol.** Messages, procedures, ids, capabilities and errors belong to superconductor/mcp; this package moves lines and runs the lifecycle around them. A protocol change goes upstream first. See [`.okf/architecture/transport.md`](.okf/architecture/transport.md).
3. **stdout carries frames only.** The server sketch routes PHP errors to stderr and writes nothing else to stdout. Logging goes through `app('log')`, never `echo`.
4. **The server is a sketch, frame-driven.** `STDIOServerSketch` reads its mail once per frame and runs the `Flow` in `STDIOLoopStartNode`; the pipe is a `WakeSource` on the loop. Latency is one frame (`SKETCH_REFRESH_RATE`). See [`.okf/server/sketch.md`](.okf/server/sketch.md).
5. **Lifecycle states say what a request may do.** `IdleState` accepts only `initialize`; `HandshakeState` accepts `initialize`, `ping` and `logging/setLevel`; `AvailableState` accepts everything but `initialize`. A refused request gets `InvalidRequest` "Method [x] is not allowed.", no new error class. See [`.okf/server/lifecycle.md`](.okf/server/lifecycle.md).
6. **Notifications route through `stdio.notifications`.** A method maps to an Action class that receives the bag and the notification. A notification not in the map is logged and ignored, never answered.
7. **Timers live on the bag and restart on every state change** (`stop_waiting`, `ping_timer`). Pings run in every state but Idle, with the server's own ids; `max_misses` unanswered stops the sketch. See [`.okf/server/lifecycle.md`](.okf/server/lifecycle.md).
8. **The client connection never blocks.** `StdioConnection` reads on wakes, writes with a `Writable` wake only while bytes wait, hands decoded lines straight to its `MCPClient` (never loop mail), and closes on timers in the spec's order: close stdin, wait, `SIGTERM`, wait, `SIGKILL`. See [`.okf/client/connection.md`](.okf/client/connection.md).
9. **Config keys are documented and mirrored.** A key added to `config/stdio.php` is described in the README and copied into any app that published the file.
10. **Tests are Pest v4 and run real children.** The connection is tested against `tests/Fixtures/echo-server.php` and `sleep`; the start node on socket pairs. No test needs a terminal or a network.
11. **Durable facts go in `.okf`.** Update the matching concept, bump its `generated.at`, append `.okf/log.md`.
12. **Versions** move with superconductor/mcp's, only past a published tag.
