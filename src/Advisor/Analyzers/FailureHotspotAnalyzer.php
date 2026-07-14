<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Advisor\Analyzers;

use Padosoft\LaravelFlow\Dashboard\StepSummary;
use Padosoft\LaravelFlowAI\Advisor\Analyzer;
use Padosoft\LaravelFlowAI\Advisor\Finding;

/**
 * Flags a node (identified by its graph node id, stable across every run of
 * the SAME flow) whose failure rate across the sampled run history exceeds
 * `$minFailureRate`, once it has been executed at least `$minSamples`
 * times — the sample-size guard exists so a node that failed once out of
 * one execution (rate 1.0) doesn't get flagged as a "hotspot" on noise
 * alone.
 *
 * @api
 */
final class FailureHotspotAnalyzer implements Analyzer
{
    public function __construct(
        private readonly float $minFailureRate = 0.3,
        private readonly int $minSamples = 3,
    ) {}

    public function analyze(string $definitionName, array $runs): array
    {
        /** @var array<string, list<StepSummary>> $byNode */
        $byNode = [];

        foreach ($runs as $run) {
            foreach ($run->steps as $step) {
                $byNode[$step->name][] = $step;
            }
        }

        $findings = [];

        foreach ($byNode as $nodeId => $steps) {
            $total = count($steps);

            if ($total < $this->minSamples) {
                continue;
            }

            $failed = array_values(array_filter($steps, static fn (StepSummary $s): bool => $s->status === 'failed'));
            $failureRate = count($failed) / $total;

            if ($failureRate < $this->minFailureRate) {
                continue;
            }

            $findings[] = new Finding(
                type: 'failure_hotspot',
                summary: sprintf('Node [%s] failed %d/%d runs (%.0f%%) in %s.', $nodeId, count($failed), $total, $failureRate * 100, $definitionName),
                rationale: [
                    'node_id' => $nodeId,
                    'handler' => $steps[0]->handler,
                    'total_runs' => $total,
                    'failed_runs' => count($failed),
                    'failure_rate' => $failureRate,
                    'error_classes' => array_values(array_unique(array_filter(
                        array_map(static fn (StepSummary $s): ?string => $s->errorClass, $failed),
                    ))),
                    // Raw, POTENTIALLY SENSITIVE error text — capped to a
                    // few samples. FlowAdvisor (the only caller of this
                    // analyzer) redacts the WHOLE finding rationale before
                    // it reaches a draft version or command output; this
                    // analyzer itself does no redaction, matching every
                    // other analyzer's "pure function of history" contract.
                    'sample_error_messages' => array_values(array_unique(array_filter(array_slice(
                        array_map(static fn (StepSummary $s): ?string => $s->errorMessage, $failed),
                        0,
                        3,
                    )))),
                ],
            );
        }

        return $findings;
    }
}
