# Update Log

## 2026-11-02

* **Update**: batches: a started 2025-03-26 session answers a batch's requests as one batch; other versions, and anything before the session starts, are refused with -32600.
* **Update**: Once the session has started, `processMail()` hands every decoded message to the `MCPServer` and queues its reply, queues `OutboundMessage` mail for its session (answers from async work, and notifications), and `fireOutgoingMessages()` sends the queue after the turn's mail. The handshake (initialize, initialized, and ping and logging/setLevel before initialized) still answers directly, since the server doesn't exist until initialized.
* **Update**: `STDIOServiceProvider` registers `StatefulCLIServerSketch` as `mcp:cli-legacy`. `StatefulServerFunctionality::decodeMessage` hands every decoded message to core's `MessageHydrator`, with the pending request's method for responses, and returns the schema object or an `MCPError`.

## 2026-11-01

* **Initialization**: Started this bundle and the package's local git repository, with [Package](orientation/package.md).
