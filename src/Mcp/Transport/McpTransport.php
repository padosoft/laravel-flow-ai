<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp\Transport;

use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpConnectionException;
use Padosoft\LaravelFlowAI\Mcp\McpClient;

/**
 * A single JSON-RPC 2.0 round trip to an MCP server. Implementations own
 * request/response correlation (matching a response to the request that
 * produced it) and MUST throw {@see McpConnectionException} for any
 * transport/protocol-level failure — a malformed response, a connection
 * drop, a timeout, or a JSON-RPC `error` object in the response envelope.
 * `request()` returns ONLY the decoded `result` object on success; MCP's
 * own tool-execution-failure semantics (a successful JSON-RPC call whose
 * RESULT carries `isError: true`) are a {@see McpClient}
 * concern, not a transport one — a transport-level error means "the call
 * itself never completed," which is a categorically different failure than
 * "the tool ran and reported failure."
 *
 * @internal
 */
interface McpTransport
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function request(string $method, array $params = []): array;

    /**
     * @param  array<string, mixed>  $params
     */
    public function notify(string $method, array $params = []): void;

    public function close(): void;
}
