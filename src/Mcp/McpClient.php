<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp;

use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpConnectionException;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpToolExecutionException;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpToolPinMismatchException;
use Padosoft\LaravelFlowAI\Mcp\Pinning\ToolPins;
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
 * When a {@see ToolPins} is supplied, every session verifies the server's
 * advertised tool contracts against their pins BEFORE any tool runs — see
 * {@see ensureVerified()} for why `callTool()` pays for a `tools/list` it
 * would otherwise not need.
 *
 * @internal
 */
final class McpClient
{
    private const PROTOCOL_VERSION = '2025-06-18';

    private bool $initialized = false;

    private bool $verified = false;

    /**
     * @param  ToolPins|null  $pins  null (the default) = no pinning: the session behaves exactly as it did before pinning existed, with no extra round trip
     */
    public function __construct(
        private readonly McpTransport $transport,
        private readonly string $clientName = 'laravel-flow-ai',
        private readonly string $clientVersion = '1.0.0',
        private readonly ?ToolPins $pins = null,
    ) {}

    /**
     * @return list<array<string, mixed>>
     *
     * @throws McpConnectionException when the server is unreachable
     * @throws McpToolPinMismatchException when pins are enforced and the
     *                                     server's catalog drifted
     */
    public function listTools(): array
    {
        $this->ensureInitialized();

        $result = $this->transport->request('tools/list');

        /** @var list<array<string, mixed>> $tools */
        $tools = is_array($result['tools'] ?? null) ? array_values($result['tools']) : [];

        // Verified BEFORE the list is returned, so a caller that feeds tool
        // descriptions into a prompt (BoundedAgentNode does exactly that)
        // never sees an unverified description at all.
        $this->pins?->verify($tools);
        $this->verified = true;

        return $tools;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<mixed> the tool's raw result content
     *
     * @throws McpConnectionException when the server is unreachable
     * @throws McpToolExecutionException when the tool itself reported failure
     * @throws McpToolPinMismatchException when pins are enforced and the
     *                                     server's catalog drifted
     */
    public function callTool(string $name, array $arguments): array
    {
        $this->ensureInitialized();
        $this->ensureVerified();

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

    /**
     * Pinning has to hold on the call path too, or it is trivially bypassed:
     * `tools/call` names a tool directly and never needs the catalog, so a
     * server could advertise honestly to whoever lists and answer a call
     * with something else entirely. So the first `callTool()` of a pinned
     * session fetches the catalog once, purely to verify it.
     *
     * That is one extra round trip per session, and only when pinning is
     * on — a caller that lists first (the bounded agent always does) has
     * already paid it, and an unpinned session never pays it at all.
     */
    private function ensureVerified(): void
    {
        if ($this->pins === null || $this->verified) {
            return;
        }

        $this->listTools();
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
