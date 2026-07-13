<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp;

use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpConnectionException;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpToolExecutionException;
use Padosoft\LaravelFlowAI\Mcp\Transport\McpTransport;
use Padosoft\LaravelFlowAI\Mcp\Transport\StdioMcpTransport;

/**
 * MCP session semantics over a {@see McpTransport}: the `initialize` →
 * `notifications/initialized` handshake (auto-run once, lazily, on first
 * use — {@see McpClientNode} never has to sequence it explicitly), tool
 * discovery, and tool invocation — mapping MCP's own success/failure
 * envelope (`result.isError`) onto a distinguishable
 * {@see McpToolExecutionException}, separate from
 * {@see McpConnectionException}
 * (thrown by the transport for anything below the tool-execution layer).
 *
 * Implements the minimal MCP client lifecycle needed to list and call tools
 * — no resource/prompt endpoints, no server-initiated requests. See
 * {@see StdioMcpTransport}'s class doc
 * for why this is hand-rolled rather than built on the official `mcp/sdk`
 * package.
 *
 * @internal
 */
final class McpClient
{
    private const PROTOCOL_VERSION = '2025-06-18';

    private bool $initialized = false;

    public function __construct(
        private readonly McpTransport $transport,
        private readonly string $clientName = 'laravel-flow-ai',
        private readonly string $clientVersion = '1.0.0',
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listTools(): array
    {
        $this->ensureInitialized();

        $result = $this->transport->request('tools/list');

        /** @var list<array<string, mixed>> $tools */
        $tools = is_array($result['tools'] ?? null) ? array_values($result['tools']) : [];

        return $tools;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<mixed> the tool's raw result content
     */
    public function callTool(string $name, array $arguments): array
    {
        $this->ensureInitialized();

        $result = $this->transport->request('tools/call', [
            'name' => $name,
            'arguments' => $arguments,
        ]);

        /** @var list<mixed> $content */
        $content = is_array($result['content'] ?? null) ? array_values($result['content']) : [];

        if (($result['isError'] ?? false) === true) {
            throw new McpToolExecutionException(
                "MCP tool [{$name}] reported failure.",
                $content,
            );
        }

        return $content;
    }

    public function close(): void
    {
        $this->transport->close();
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->transport->request('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => new \stdClass,
            'clientInfo' => [
                'name' => $this->clientName,
                'version' => $this->clientVersion,
            ],
        ]);

        $this->transport->notify('notifications/initialized');

        $this->initialized = true;
    }
}
