<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp\Transport;

/**
 * Production {@see McpTransportFactory}: builds a real {@see StdioMcpTransport}
 * (spawns an actual child process) for every call.
 *
 * @internal
 */
final class StdioMcpTransportFactory implements McpTransportFactory
{
    public function __construct(
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function stdio(string $command, array $args): McpTransport
    {
        return new StdioMcpTransport($command, $args, $this->timeoutSeconds);
    }
}
