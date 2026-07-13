<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp\Transport;

use Padosoft\LaravelFlowAI\Nodes\McpClientNode;

/**
 * Builds a fresh {@see McpTransport} for one node execution. A factory
 * (rather than a single injected `McpTransport` instance) because — unlike
 * `LlmClient`, whose target provider is fixed application-wide config —
 * {@see McpClientNode}'s server command/args
 * come from wired INPUT ports and can differ on every execution; the node
 * needs a NEW transport (and, for stdio, a new subprocess) per call.
 *
 * @internal
 */
interface McpTransportFactory
{
    /**
     * @param  list<string>  $args
     */
    public function stdio(string $command, array $args): McpTransport;
}
