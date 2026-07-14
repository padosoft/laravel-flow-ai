<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Advisor\Analyzers;

use Padosoft\LaravelFlow\Dashboard\StepSummary;
use Padosoft\LaravelFlowAI\Advisor\Analyzer;
use Padosoft\LaravelFlowAI\Advisor\Finding;

/**
 * Flags a node whose MOST RECENT execution took meaningfully longer than
 * its own historical average (mean + `$stdDeviations` standard deviations),
 * once enough samples exist to make that average meaningful.
 *
 * Covers the plan's "cost/duration outlier" analyzer type for DURATION
 * only — `Dashboard\StepSummary` (core's dashboard read model, the only
 * per-node read surface `FlowDashboardReadModel` exposes) carries
 * `durationMs` but no per-node cost breakdown; `businessImpact` is only
 * available at the RUN level (`Dashboard\RunDetail::$businessImpact`), not
 * attributable to a specific node. A true per-node cost outlier analyzer
 * would need core to persist/expose per-node business impact, which it
 * does not today — documented here as a known scope boundary rather than
 * silently pretended away, per this program's established practice (see
 * `Mcp\FlowToolServer::inputSchemaFor()`'s docblock for the precedent).
 *
 * @api
 */
final class DurationOutlierAnalyzer implements Analyzer
{
    public function __construct(
        private readonly float $stdDeviations = 2.0,
        private readonly int $minSamples = 5,
    ) {}

    public function analyze(string $definitionName, array $runs): array
    {
        /** @var array<string, list<StepSummary>> $byNode */
        $byNode = [];

        // $runs is most-recent-first; the FIRST entry seen per node is
        // therefore its most recent execution.
        foreach ($runs as $run) {
            foreach ($run->steps as $step) {
                $byNode[$step->name][] = $step;
            }
        }

        $findings = [];

        foreach ($byNode as $nodeId => $steps) {
            $durations = array_values(array_filter(
                array_map(static fn (StepSummary $s): ?int => $s->durationMs, $steps),
                static fn (?int $d): bool => $d !== null,
            ));

            if (count($durations) < $this->minSamples) {
                continue;
            }

            $latest = $durations[0];
            $mean = array_sum($durations) / count($durations);
            $variance = array_sum(array_map(static fn (int $d): float => ($d - $mean) ** 2, $durations)) / count($durations);
            $stdDev = sqrt($variance);
            $threshold = $mean + ($this->stdDeviations * $stdDev);

            if ($stdDev <= 0.0 || $latest <= $threshold) {
                continue;
            }

            $findings[] = new Finding(
                type: 'duration_outlier',
                summary: sprintf('Node [%s] in %s took %dms, %.1f standard deviations above its %dms average.', $nodeId, $definitionName, $latest, ($latest - $mean) / $stdDev, (int) round($mean)),
                rationale: [
                    'node_id' => $nodeId,
                    'handler' => $steps[0]->handler,
                    'sample_size' => count($durations),
                    'latest_duration_ms' => $latest,
                    'mean_duration_ms' => $mean,
                    'std_dev_ms' => $stdDev,
                ],
            );
        }

        return $findings;
    }
}
