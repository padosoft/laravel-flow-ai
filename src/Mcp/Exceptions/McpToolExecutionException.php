<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp\Exceptions;

/**
 * The MCP call completed successfully at the protocol level, but the
 * server's result carried `isError: true` — the tool itself reported a
 * failure (a bad argument, an internal error on the server side, etc.).
 * `$content` is the tool's raw result content array (whatever shape the
 * server returned), preserved so a caller can surface the tool's own error
 * detail rather than just a generic message.
 *
 * @api
 */
final class McpToolExecutionException extends McpException
{
    /**
     * @param  array<int, mixed>  $content
     */
    public function __construct(string $message, public readonly array $content)
    {
        parent::__construct($message);
    }
}
