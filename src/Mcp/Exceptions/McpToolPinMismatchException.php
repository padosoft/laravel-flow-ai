<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp\Exceptions;

use Padosoft\LaravelFlowAI\Mcp\Pinning\PinViolation;
use Padosoft\LaravelFlowAI\Mcp\Pinning\ToolPins;

/**
 * Thrown by {@see ToolPins::verify()} in `enforce` mode when the tools an
 * MCP server advertises no longer match the contracts that were pinned —
 * a changed description or schema, a pinned tool that vanished, or a tool
 * the server added to a closed catalog.
 *
 * A THIRD failure class alongside {@see McpConnectionException} (the call
 * never completed) and {@see McpToolExecutionException} (the call completed,
 * the tool said no): here the server answered perfectly well, and that is
 * precisely the problem — it answered with something other than what was
 * approved. Distinguishable by type for the same reason those two are: an
 * operator paged at 3am needs to know instantly whether this is a broken
 * server or a changed one.
 *
 * The full violation list is carried on the exception rather than flattened
 * into the message, so a host can render or ship it (the message keeps a
 * readable summary for logs).
 *
 * @api
 */
final class McpToolPinMismatchException extends McpException
{
    /**
     * @param  list<PinViolation>  $violations
     */
    public function __construct(
        public readonly string $serverId,
        public readonly array $violations,
    ) {
        parent::__construct(sprintf(
            'MCP server [%s] does not match its pinned tool contracts: %s. The call was blocked before it happened.',
            $serverId,
            implode('; ', array_map(static fn (PinViolation $v): string => $v->describe(), $violations)),
        ));
    }
}
