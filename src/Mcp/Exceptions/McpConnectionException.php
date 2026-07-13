<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp\Exceptions;

/**
 * The MCP call itself never completed: the server process couldn't be
 * spawned, the connection/handshake failed, a response timed out, a
 * response was malformed, or the server returned a JSON-RPC `error` object
 * instead of a `result`. Distinguish from {@see McpToolExecutionException},
 * where the call DID complete but the tool reported failure — a connection
 * failure means nothing about the tool's own logic ran at all.
 *
 * @api
 */
final class McpConnectionException extends McpException {}
