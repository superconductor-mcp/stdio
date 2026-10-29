# Log

## 2026-10-28 (0.11.0)

* **Package renamed** `semiconductor/stdio` to `superconductor/stdio`; it requires `superconductor/core` (formerly `superconductor/mcp`) `^0.11.0` ([transport](architecture/transport.md)).
* **Contracts**: the sketch registers the running server under `Contracts\Server\MCPServer` and holds it through that type; the `stdio` creator returns `Contracts\Client\MCPClient`. `StdioConnection` holds the concrete `Fabricate\Client\MCPClient`, because `receive()` and `connectionClosed()` are not on the contract; `ClientStatus` is now `Contracts\Client\ClientStatus` ([sketch](server/sketch.md), [connection](client/connection.md)).
* **Tests** read core's `config/mcp.php` from `vendor/superconductor/core` instead of a sibling checkout; `venusian/framework ^0.11.0` joins require-dev because the code calls `config()` and `app()`. PHP is `~8.4.27||^8.5.12`.
* No wire or behaviour change; the protocol line is unchanged (2025-06-18, 2025-03-26, 2024-11-05). Pest suite: 14 tests, 53 assertions.

## 2026-10-27 (2025-06-18)

* **Batches by version**: the pipe posts a batch line whole (`BatchReceived`); the node opens it when `takesBatches()` and refuses it with one `InvalidRequest` from a 2025-06-18 session ([sketch](server/sketch.md)).
* **Answers that are not pings go to the session**, so elicitation works over the pipe.
* Pest suite: 14 tests, 53 assertions.

## 2026-10-26 (2025-03-26)

* **Session logic moved up** to superconductor/mcp `Mechanism\Session`; `STDIOBag` extends `SessionBag`, the node delegates requests, notifications and decoder errors, keeps pings and timers ([lifecycle](server/lifecycle.md)). Config action classes now `Fabricate\Mechanism\Session\Actions\*`.
* **Batch lines taken on both ends**: one mail per element on the server, one list to the client; replies stay one per line ([transport](architecture/transport.md)).
* Protocol versions follow superconductor/mcp: 2025-03-26 proposed, 2024-11-05 kept.
* Pest suite: 12 tests, 45 assertions.

## 2026-10-26

* **Client end**: `StdioConnection` and the `stdio` creator on `mcp.clients`; a missing command is named before anything is spawned; close runs on loop timers in the spec's order ([connection](client/connection.md)). Proven against the dev app's concept server, neo4j-mcp 1.5.3 and `sleep`.
* **Two id ledgers** on the bag; `ping_time.enabled` back to `true` ([lifecycle](server/lifecycle.md)).
* **Decoder errors go out as built**, unknown notifications never; async procedures run in a fiber through `respond()` ([sketch](server/sketch.md)).
* Pest suite: 11 tests, 37 assertions. README, SECURITY, AGENTS, CLAUDE, CI added.

## 2026-10-25

* Server end complete for 2024-11-05: sketch, pipe, lifecycle states, timers, notifications map, server pings, outbox flush every frame.
