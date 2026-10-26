---
type: Server
title: Lifecycle
description: Idle, Handshake, Available; the timers on the bag; server pings with their own ids; notifications through the config map.
resource: src/StateMachines/Lifecycle
tags: [mcp, stdio, lifecycle, ping]
status: draft
generated: { by: claude-fable/5.1, at: 2026-10-26T04:30:00Z }
sources:
  - id: states
    resource: src/StateMachines/Lifecycle/LifecycleState.php
    title: LifecycleState
  - id: bag
    resource: src/STDIOBag.php
    title: STDIOBag
  - id: node
    resource: src/Workflows/STDIOLoopStartNode.php
    title: STDIOLoopStartNode
---

# States

| State | `canProcess()` | moves to |
|---|---|---|
| `IdleState` | `initialize` only; even `ping` is refused, deliberately | Handshake on the `initialize` response |
| `HandshakeState` | `initialize`, `ping`, `logging/setLevel` | Available on `notifications/initialized` (`InitializedAction`) |
| `AvailableState` | everything but `initialize` | — |

A refusal is `InvalidRequest` with the request's id. Entering Idle clears the ledgers' context: pending pings and misses.

# The bag

`STDIOBag` extends `MCPServerBag` with `state`, `their_ids`, `own_ids`, `stop_waiting`, `ping_timer`, `pending_pings`, `ping_misses`, `in_flight`, `cancelled`.

# Timers restart on every state change

`stateChanged()` cancels and re-arms: `stop_waiting` (`handshake.stop_waiting`) only in Handshake, firing `HandshakeTimedOut`; `ping_timer` (`ping_time`) in every state but Idle, firing `PingDue` every `ttl` seconds.

# Pings

`sendPing()`: a ping still pending counts a miss (logged); `max_misses` in a row returns `ClientUnresponsive` and the sketch stops. Otherwise a `PingRequest` with `own_ids->next()` goes out and waits in `pending_pings`. Any pong to a pending id clears every pending ping and the misses.

Ids are per requestor: the server's 0, 1, 2… never collide with the client's 0, 1, 2…, which is what let pings come back on (they were off while one shared ledger refused the Inspector's ids).

# Notifications

`stdio.notifications` maps a method to an Action class with `handle(STDIOBag &$bag, ?Notification $notification)`. Shipped: `notifications/initialized` → `InitializedAction` (next state, cancel `stop_waiting`); `notifications/cancelled` → `CancelledAction` (log; mark `cancelled[id]` only when the request is in flight, so its response is dropped). A developer adds a method to the map to handle more; a method not in the map is logged and ignored.
