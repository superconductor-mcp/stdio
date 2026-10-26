# Log

## 2026-10-26

* **Client end**: `StdioConnection` and the `stdio` creator on `mcp.clients`; a missing command is named before anything is spawned; close runs on loop timers in the spec's order ([connection](client/connection.md)). Proven against the dev app's concept server, neo4j-mcp 1.5.3 and `sleep`.
* **Two id ledgers** on the bag; `ping_time.enabled` back to `true` ([lifecycle](server/lifecycle.md)).
* **Decoder errors go out as built**, unknown notifications never; async procedures run in a fiber through `respond()` ([sketch](server/sketch.md)).
* Pest suite: 11 tests, 37 assertions. README, SECURITY, AGENTS, CLAUDE, CI added.

## 2026-10-25

* Server end complete for 2024-11-05: sketch, pipe, lifecycle states, timers, notifications map, server pings, outbox flush every frame.
