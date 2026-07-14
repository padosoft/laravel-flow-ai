<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Advisor\Analyzers;

use Padosoft\LaravelFlowAI\Advisor\Analyzer;
use Padosoft\LaravelFlowAI\Advisor\Finding;

/**
 * Flags a flow that is DECLARED as an MCP tool (`config/laravel-flow-ai.php`'s
 * `mcp.exposed_flows` allowlist — see `Mcp\FlowToolServer`, F-PR5) but has
 * zero recorded runs — a candidate to un-expose or investigate as dead.
 *
 * Scoped to MCP-exposed flow names specifically (not, e.g., a bounded
 * agent's configured tool allowlist) because that is the only "declared
 * tool" surface `Dashboard\FlowDashboardReadModel` can actually cross-
 * reference against run history without per-node output introspection —
 * `Dashboard\StepSummary` carries no parsed node output, so which SPECIFIC
 * tool name a `BoundedAgentNode` run actually invoked is not observable
 * through this read model today. Documented as a known scope boundary, not
 * silently pretended away.
 *
 * @api
 */
final class UnusedToolAnalyzer implements Analyzer
{
    /**
     * @param  list<string>  $exposedFlowNames
     */
    public function __construct(
        private readonly array $exposedFlowNames,
    ) {}

    public function analyze(string $definitionName, array $runs): array
    {
        if ($runs !== [] || ! in_array($definitionName, $this->exposedFlowNames, true)) {
            return [];
        }

        return [new Finding(
            type: 'unused_tool',
            summary: sprintf('Flow [%s] is exposed as an MCP tool but has never been run.', $definitionName),
            rationale: [
                'definition_name' => $definitionName,
                'declared_as' => 'mcp.exposed_flows',
            ],
        )];
    }
}
