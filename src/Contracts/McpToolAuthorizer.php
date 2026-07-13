<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Contracts;

use Padosoft\LaravelFlowAI\Mcp\Authorization\AllowAllMcpToolAuthorizer;
use Padosoft\LaravelFlowAI\Mcp\Authorization\DenyAllMcpToolAuthorizer;
use Padosoft\LaravelFlowAI\Mcp\FlowToolServer;

/**
 * Authorization hook bound by host applications to gate which flows this
 * package's MCP server exposes as tools, and to whom. Consulted by
 * {@see FlowToolServer} — a `canListTools()`
 * denial makes `listTools()` return an empty list (nothing advertised at
 * all, not merely a filtered one), and a `canInvokeTool()` denial for a
 * SPECIFIC flow name removes that flow from the listing entirely — an
 * opted-out or denied flow is genuinely INVISIBLE, never surfaced as "exists
 * but you can't call it," so a caller (potentially an untrusted or
 * semi-trusted LLM agent) cannot even learn the tool's name/schema.
 *
 * Each method receives an actor metadata array the MCP transport layer
 * supplies (shape is transport-defined — this package does not interpret
 * it, same posture as core's `DashboardActionAuthorizer`).
 *
 * Default binding registered by the service provider is
 * {@see DenyAllMcpToolAuthorizer},
 * which denies every check so no flow is ever exposed as an MCP tool without
 * an explicit host-application policy. For local development, bind
 * {@see AllowAllMcpToolAuthorizer}
 * explicitly.
 *
 * @api
 */
interface McpToolAuthorizer
{
    /**
     * @param  array<string, mixed>|null  $actor
     */
    public function canListTools(?array $actor): bool;

    /**
     * `$flowName` is also called with {@see FlowToolServer::STATUS_CHECK_TOOL_NAME},
     * the server's built-in status-polling tool — that check is deliberately
     * NOT tied to `canListTools()` or to any specific flow's own
     * authorization, so an actor who can invoke a flow but not list the
     * catalog (an "invoke-only" actor) can still poll the run it started.
     * A host that wants status polling available whenever ANY flow is
     * invokable for the actor should special-case this name and return
     * `true`; a host that wants it opt-in per actor controls that here too.
     *
     * @param  array<string, mixed>|null  $actor
     */
    public function canInvokeTool(string $flowName, ?array $actor): bool;
}
