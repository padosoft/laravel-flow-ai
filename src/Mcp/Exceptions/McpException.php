<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp\Exceptions;

use Padosoft\LaravelFlowAI\Nodes\McpClientNode;
use RuntimeException;

/**
 * Base type for every MCP client failure. Never thrown directly — catch
 * {@see McpConnectionException} and {@see McpToolExecutionException}
 * separately when the distinction matters (it does for
 * {@see McpClientNode}: a connection failure
 * and a tool-reported failure are different enough failure classes that a
 * flow author or a future Advisor needs to tell them apart), or catch this
 * base type when only "something about this MCP call failed" matters.
 *
 * @api
 */
abstract class McpException extends RuntimeException {}
