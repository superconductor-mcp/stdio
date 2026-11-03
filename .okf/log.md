# Update Log

## 2026-11-03

* **Update**: [Connecting a client over stdio](transport/client.md): `StdioClientConnection` is the whole connection, handshake and session, implementing core's `ClientConnection` contract directly; nothing of it is in core. **Fix**: messages the server sent before answering the handshake were dropped; they now reach the client. A legacy server exiting fires `ConnectionFailed` and answers requests in flight with the reason; a relaunched 2026-07-28 server's lost requests are answered too.
* **Creation**: [Connecting a client over stdio](transport/client.md): `StdioServerDefinition`, `StdioClientConnection` and `StdioClientPipe`: server/discover probe with fallback to initialize, the handshake before `MCPClient` exists, async mail through `deliver()`, close/SIGTERM/SIGKILL shutdown, and relaunching a 2026-07-28 server that exits. Tests: `tests/Feature/ClientConnectionTest.php` against `tests/Fixtures/fake-server.php`.
* **Fix**: [Package](orientation/package.md): `mcp:cli` lost the subscription teardown sent when stdin closed, stopping before the mail carrying it arrived; it now stops on `ServerClosed`, posted behind it. `mcp:cli-legacy` answered messages read together with `notifications/initialized` as if the session hadn't started; they now go to the started server. First tests: `tests/Feature/ServerFunctionalityTest.php`.
* **Creation**: `mcp:cli`, the 2026-07-28 stdio server. **Update**: [Package](orientation/package.md).

## 2026-11-02

* **Update**: batches: a started 2025-03-26 session answers a batch's requests as one batch; other versions, and anything before the session starts, are refused with -32600.
* **Update**: Once the session has started, `processMail()` hands every decoded message to the `MCPServer` and queues its reply, queues `OutboundMessage` mail for its session (answers from async work, and notifications), and `fireOutgoingMessages()` sends the queue after the turn's mail. The handshake (initialize, initialized, and ping and logging/setLevel before initialized) still answers directly, since the server doesn't exist until initialized.
* **Update**: `STDIOServiceProvider` registers `StatefulCLIServerSketch` as `mcp:cli-legacy`. `StatefulServerFunctionality::decodeMessage` hands every decoded message to core's `MessageHydrator`, with the pending request's method for responses, and returns the schema object or an `MCPError`.

## 2026-11-01

* **Initialization**: Started this bundle and the package's local git repository, with [Package](orientation/package.md).
