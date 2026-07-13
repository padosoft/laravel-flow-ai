<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp\Authorization;

use Padosoft\LaravelFlowAI\Contracts\McpToolAuthorizer;

/**
 * Default deny-by-default binding for {@see McpToolAuthorizer}.
 *
 * Denies every check so no flow is ever exposed as an MCP tool in a fresh
 * install without an explicit host-application policy. For local
 * development against a single trusted operator, bind
 * {@see AllowAllMcpToolAuthorizer} explicitly:
 *
 *     $this->app->bind(McpToolAuthorizer::class, AllowAllMcpToolAuthorizer::class);
 *
 * @api
 */
final class DenyAllMcpToolAuthorizer implements McpToolAuthorizer
{
    public function canListTools(?array $actor): bool
    {
        return false;
    }

    public function canInvokeTool(string $flowName, ?array $actor): bool
    {
        return false;
    }
}
