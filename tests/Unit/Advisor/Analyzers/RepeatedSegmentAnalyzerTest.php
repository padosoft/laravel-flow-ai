<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Advisor\Analyzers;

use Padosoft\LaravelFlow\Dashboard\RunDetail;
use Padosoft\LaravelFlow\Dashboard\RunSummary;
use Padosoft\LaravelFlow\Dashboard\StepSummary;
use Padosoft\LaravelFlowAI\Advisor\Analyzers\RepeatedSegmentAnalyzer;
use PHPUnit\Framework\TestCase;

final class RepeatedSegmentAnalyzerTest extends TestCase
{
    private function step(string $name, int $sequence): StepSummary
    {
        return new StepSummary(
            id: $sequence,
            runId: 'run-1',
            name: $name,
            handler: 'SomeHandler',
            sequence: $sequence,
            status: 'succeeded',
            errorClass: null,
            errorMessage: null,
            durationMs: 10,
            startedAt: null,
            finishedAt: null,
        );
    }

    /**
     * @param  list<string>  $nodeIds
     */
    private function fixtureRun(string $runId, array $nodeIds): RunDetail
    {
        $steps = [];

        foreach ($nodeIds as $i => $nodeId) {
            $steps[] = $this->step($nodeId, $i + 1);
        }

        return new RunDetail(
            run: new RunSummary($runId, 'flow-a', 'succeeded', false, null, false, null, null, null, null, null, null, null),
            steps: $steps,
            audit: [],
            approvals: [],
            webhookOutbox: [],
            input: null,
            output: null,
            businessImpact: null,
        );
    }

    public function test_flags_a_sequence_recurring_across_enough_distinct_runs(): void
    {
        $analyzer = new RepeatedSegmentAnalyzer(minRuns: 3);
        $runs = [
            $this->fixtureRun('run-1', ['fetch', 'validate', 'store']),
            $this->fixtureRun('run-2', ['fetch', 'validate', 'store']),
            $this->fixtureRun('run-3', ['fetch', 'validate', 'store']),
            $this->fixtureRun('run-4', ['unrelated']),
        ];

        $findings = $analyzer->analyze('flow-a', $runs);

        $this->assertNotEmpty($findings);
        $this->assertSame('repeated_segment', $findings[0]->type);
        $this->assertSame(['fetch', 'validate', 'store'], $findings[0]->rationale['node_sequence']);
        $this->assertSame(3, $findings[0]->rationale['run_count']);
    }

    public function test_a_longer_flagged_segment_suppresses_its_own_sub_segments(): void
    {
        $analyzer = new RepeatedSegmentAnalyzer(minRuns: 3);
        $runs = [
            $this->fixtureRun('run-1', ['fetch', 'validate', 'store']),
            $this->fixtureRun('run-2', ['fetch', 'validate', 'store']),
            $this->fixtureRun('run-3', ['fetch', 'validate', 'store']),
        ];

        $findings = $analyzer->analyze('flow-a', $runs);

        // The 3-node segment [fetch,validate,store] covers its own 2-node
        // sub-segments ([fetch,validate] and [validate,store]) — those must
        // not ALSO surface as redundant separate findings.
        $this->assertCount(1, $findings);
        $this->assertSame(['fetch', 'validate', 'store'], $findings[0]->rationale['node_sequence']);
    }

    public function test_below_the_min_runs_threshold_is_not_flagged(): void
    {
        $analyzer = new RepeatedSegmentAnalyzer(minRuns: 3);
        $runs = [
            $this->fixtureRun('run-1', ['fetch', 'validate', 'store']),
            $this->fixtureRun('run-2', ['fetch', 'validate', 'store']),
        ];

        $this->assertSame([], $analyzer->analyze('flow-a', $runs));
    }
}
