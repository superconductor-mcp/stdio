# Superconductor MCP stdio

[![Tests](https://github.com/superconductor-mcp/stdio/actions/workflows/tests.yml/badge.svg)](https://github.com/superconductor-mcp/stdio/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/superconductor/stdio.svg)](https://packagist.org/packages/superconductor/stdio)
[![Total Downloads](https://img.shields.io/packagist/dt/superconductor/stdio.svg)](https://packagist.org/packages/superconductor/stdio)
[![License](https://img.shields.io/packagist/l/superconductor/stdio.svg)](LICENSE)

## About Superconductor MCP stdio

> **Note:** This repository contains the stdio transport for Superconductor MCP. The protocol itself lives in [superconductor/core](https://github.com/superconductor-mcp/core).

Superconductor MCP stdio runs your application's MCP servers as local processes and connects your MCP clients to anyone else's, over stdin and stdout.

Want Claude Desktop, an IDE or any other MCP host to launch your app as a server? Point it at `php rocket mcp:cli-legacy your-server` and it talks MCP over the process's pipes, in whichever handshake version the host asks for, 2024-11-05 through 2025-11-25. A host that speaks 2026-07-28 gets `php rocket mcp:cli your-server` instead.

Going the other way, a client of yours can launch any stdio MCP server, whatever it is written in, and talk to it from your app's event loop. It asks the server for 2026-07-28 first, falls back to `initialize` when the server is older, and relaunches a modern server that exits.

> **Built with AI assistance.** This package was developed LLM-assisted — not vibe-coded. Generated code was deliberately prompted and reviewed by a professional software engineer. See [AI-assisted development](#ai-assisted-development).

## Learning Superconductor MCP stdio

Superconductor MCP stdio has documentation on the [Venusian website](https://venusian.projectsaturnstudios.com/docs/superconductor/stdio), alongside the guides for [installing Superconductor](https://venusian.projectsaturnstudios.com/docs/superconductor/installation), [writing servers](https://venusian.projectsaturnstudios.com/docs/superconductor/servers) and [connecting clients](https://venusian.projectsaturnstudios.com/docs/superconductor/clients).

## AI-assisted development

This codebase was built with LLM assistance under human direction. Prompts, reviews, and architectural decisions were owned by a professional software engineer — the model accelerates drafting and exploration; it does not replace judgment on APIs, dependency direction, or what ships.

## Contributing

Thank you for considering contributing to Superconductor MCP! The contribution guide can be found in the [Venusian documentation](https://venusian.projectsaturnstudios.com/docs/contributions).

## Code of Conduct

In order to ensure that the Venusian community is welcoming to all, please review and abide by the [Code of Conduct](https://venusian.projectsaturnstudios.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

Please review [our security policy](https://github.com/superconductor-mcp/stdio/security/policy) on how to report security vulnerabilities.

## License

Superconductor MCP stdio is open-sourced software licensed under the [MIT license](LICENSE).
