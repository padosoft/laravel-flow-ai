<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp\Exceptions;

use Padosoft\LaravelFlowAI\Mcp\FlowToolServer;
use RuntimeException;

/**
 * Thrown by {@see FlowToolServer::callTool()} for
 * an unknown, unconfigured, or authorizer-denied tool name — a
 * PROTOCOL-level error (the tool does not exist as far as this caller is
 * concerned), distinct from a tool executing and reporting its OWN failure
 * (which the server returns as an `isError: true` result envelope instead of
 * throwing). Deliberately carries the SAME message for "genuinely unknown"
 * and "exists but denied" — never leaking which case applies, so a denied
 * flow's mere existence is not discoverable by probing tool names.
 *
 * @api
 */
final class McpToolNotFoundException extends RuntimeException {}
