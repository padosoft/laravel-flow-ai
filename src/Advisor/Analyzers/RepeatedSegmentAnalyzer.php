<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Advisor\Analyzers;

use Padosoft\LaravelFlowAI\Advisor\Analyzer;
use Padosoft\LaravelFlowAI\Advisor\Finding;

/**
 * Flags a contiguous 2- or 3-node execution sequence (by graph node id,
 * already execution-ordered by `Dashboard\FlowDashboardReadModel`) that
 * recurs across at least `$minRuns` DISTINCT runs of the same flow — a
 * candidate to extract into a reusable sub-flow. Counts DISTINCT RUNS
 * containing the sequence at least once, not total occurrences, so one run
 * with a long internal loop cannot dominate the signal on its own.
 *
 * @api
 */
final class RepeatedSegmentAnalyzer implements Analyzer
{
    public function __construct(
        private readonly int $minRuns = 3,
    ) {}

    public function analyze(string $definitionName, array $runs): array
    {
        /** @var array<string, int> $runCountBySegment */
        $runCountBySegment = [];
        /** @var array<string, list<string>> $segmentNodes */
        $segmentNodes = [];

        foreach ($runs as $run) {
            $nodeIds = array_map(static fn ($step): string => $step->name, $run->steps);
            $seenInThisRun = [];

            foreach ([2, 3] as $length) {
                for ($i = 0; $i + $length <= count($nodeIds); $i++) {
                    $segment = array_slice($nodeIds, $i, $length);
                    $key = implode(' > ', $segment);

                    if (isset($seenInThisRun[$key])) {
                        continue;
                    }

                    $seenInThisRun[$key] = true;
                    $runCountBySegment[$key] = ($runCountBySegment[$key] ?? 0) + 1;
                    $segmentNodes[$key] = $segment;
                }
            }
        }

        $findings = [];

        // Longer segments first: a flagged 3-node segment makes its own
        // 2-node sub-segments redundant noise once already reported.
        uksort($runCountBySegment, static fn (string $a, string $b): int => count($segmentNodes[$b]) <=> count($segmentNodes[$a]));

        $reported = [];

        foreach ($runCountBySegment as $key => $runCount) {
            if ($runCount < $this->minRuns) {
                continue;
            }

            $segment = $segmentNodes[$key];
            $alreadyCovered = false;

            foreach ($reported as $reportedSegment) {
                if (self::containsSubsequence($reportedSegment, $segment)) {
                    $alreadyCovered = true;

                    break;
                }
            }

            if ($alreadyCovered) {
                continue;
            }

            $reported[] = $segment;

            $findings[] = new Finding(
                type: 'repeated_segment',
                summary: sprintf('Node sequence [%s] recurs across %d runs of %s — a candidate for a reusable sub-flow.', $key, $runCount, $definitionName),
                rationale: [
                    'node_sequence' => $segment,
                    'run_count' => $runCount,
                    'total_runs_sampled' => count($runs),
                ],
            );
        }

        return $findings;
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needle
     */
    private static function containsSubsequence(array $haystack, array $needle): bool
    {
        $needleLength = count($needle);

        for ($i = 0; $i + $needleLength <= count($haystack); $i++) {
            if (array_slice($haystack, $i, $needleLength) === $needle) {
                return true;
            }
        }

        return false;
    }
}
