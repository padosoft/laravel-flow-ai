<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp\Authorization;

use Padosoft\LaravelFlowAI\Contracts\McpToolAuthorizer;

/**
 * Explicit dev-only opt-in for {@see McpToolAuthorizer}: allows every check.
 * Never the default binding — a host application must bind this (or its own
 * implementation) deliberately. Exposing every configured flow to every MCP
 * caller with no policy is almost never the right production posture; this
 * exists for local development against a single trusted operator/agent.
 *
 * @api
 */
final class AllowAllMcpToolAuthorizer implements McpToolAuthorizer
{
    public function canListTools(?array $actor): bool
    {
        return true;
    }

    public function canInvokeTool(string $flowName, ?array $actor): bool
    {
        return true;
    }
}
