<?php

namespace SuperconductorMCP\STDIO\ServerDefinitions;

use Fabricate\Client\ClientConfiguration;
use Fabricate\Mechanism\ServerDefinitions\MCPServerDefinition;
use SuperconductorMCP\STDIO\StdioClientConnection;

/**
 * A server a client launches as a subprocess and talks to over its stdin and stdout.
 */
class StdioServerDefinition extends MCPServerDefinition
{
    /**
     * @param list<string> $args
     * @param array<string, string> $env Added to the client process's environment for the server.
     */
    public function __construct(
        public readonly string $command,
        public readonly array $args = [],
        public readonly array $env = [],
    ) {}

    public function connect(ClientConfiguration $client): StdioClientConnection
    {
        $connection = new StdioClientConnection($client, $this->command, $this->args, $this->env);
        $connection->open();

        return $connection;
    }
}
