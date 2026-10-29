# Security Policy

## Supported versions

superconductor/stdio is pre-1.0. No 0.x release receives security fixes or advisories; fixes land in the
next release line. Security support starts with 1.0.

| Version | Security fixes |
|---------|----------------|
| < 1.0   | No             |

## Reporting a vulnerability

Please don't open a public issue for a security problem.

Report it privately through GitHub: the **Report a vulnerability** button on the
[superconductor-mcp/stdio](https://github.com/superconductor-mcp/stdio) repository's **Security** tab.
If that isn't available, email **info@projectsaturnstudios.com**.

Include what you found, the affected version, the protocol version in play, the transport, and steps
to reproduce. Reports are read and weighed for the release line in development; before 1.0 there
is no response-time commitment.
## Security model

superconductor/stdio is the stdio transport for superconductor/core: newline-delimited JSON-RPC over a process's
own standard streams. The protocol checks (decoding, lifecycle, ids, capabilities) are superconductor/core's
and are described in [its SECURITY.md](https://github.com/superconductor-mcp/core/blob/main/SECURITY.md).
This package adds the two ends of the pipe.

- **Server end: `rocket mcp:cli <server>`.** The sketch reads stdin and writes stdout, nothing else. Whoever
  started the process is the client; there is no listening socket, no network, and no second client. PHP
  warnings are routed to stderr so a stray message can never be read as a frame.
- **Client end: `StdioConnection`.** The server named by an `mcp_servers` entry is started with `proc_open`
  and the entry's command, arguments, working directory and environment, on top of the application's own
  environment. Its stdout is the only thing read; its stderr is kept, 2 KiB at most, so a failed start can
  say why. Nothing from the child is executed or evaluated.
- **Shutdown is bounded.** A connection closes in the spec's order: stdin closed, `stdio.client.shutdown_grace`
  seconds to exit, `SIGTERM`, the same grace again, `SIGKILL`. A server that ignores the first two steps is
  killed; none is left running.
- **A dropped client is a stopped server.** Unanswered pings past `stdio.ping_time.max_misses`, a handshake
  past `stdio.handshake.stop_waiting.ttl`, or a closed pipe each stop the server sketch.

A report is in scope when this package reads from or writes to something other than the streams it is
given, leaves a child process running after a close, or lets a frame through that superconductor/core's
decoder would have refused.
