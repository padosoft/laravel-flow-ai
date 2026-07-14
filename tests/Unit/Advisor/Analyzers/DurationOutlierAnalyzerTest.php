<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Advisor\Analyzers;

use Padosoft\LaravelFlow\Dashboard\RunDetail;
use Padosoft\LaravelFlow\Dashboard\RunSummary;
use Padosoft\LaravelFlow\Dashboard\StepSummary;
use Padosoft\LaravelFlowAI\Advisor\Analyzers\DurationOutlierAnalyzer;
use PHPUnit\Framework\TestCase;

final class DurationOutlierAnalyzerTest extends TestCase
{
    private function step(string $name, int $durationMs): StepSummary
    {
        return new StepSummary(
            id: 1,
            runId: 'run-1',
            name: $name,
            handler: 'SomeHandler',
            sequence: 1,
            status: 'succeeded',
            errorClass: null,
            errorMessage: null,
            durationMs: $durationMs,
            startedAt: null,
            finishedAt: null,
        );
    }

    private function fixtureRun(string $runId, array $steps): RunDetail
    {
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

    public function test_flags_a_recent_execution_far_above_its_historical_average(): void
    {
        $analyzer = new DurationOutlierAnalyzer(stdDeviations: 2.0, minSamples: 5);
        // Most-recent-first: run-6 (the outlier) comes first.
        $runs = [
            $this->fixtureRun('run-6', [$this->step('slow', 50_000)]),
            $this->fixtureRun('run-5', [$this->step('slow', 100)]),
            $this->fixtureRun('run-4', [$this->step('slow', 105)]),
            $this->fixtureRun('run-3', [$this->step('slow', 95)]),
            $this->fixtureRun('run-2', [$this->step('slow', 100)]),
            $this->fixtureRun('run-1', [$this->step('slow', 100)]),
        ];

        $findings = $analyzer->analyze('flow-a', $runs);

        $this->assertCount(1, $findings);
        $this->assertSame('duration_outlier', $findings[0]->type);
        $this->assertSame('slow', $findings[0]->rationale['node_id']);
        $this->assertSame(50_000, $findings[0]->rationale['latest_duration_ms']);
    }

    public function test_below_the_sample_size_guard_is_not_flagged(): void
    {
        $analyzer = new DurationOutlierAnalyzer(stdDeviations: 2.0, minSamples: 5);
        $runs = [
            $this->fixtureRun('run-2', [$this->step('slow', 50_000)]),
            $this->fixtureRun('run-1', [$this->step('slow', 100)]),
        ];

        $this->assertSame([], $analyzer->analyze('flow-a', $runs));
    }

    public function test_uniform_durations_are_never_flagged(): void
    {
        $analyzer = new DurationOutlierAnalyzer(stdDeviations: 2.0, minSamples: 5);
        $runs = array_map(
            fn (int $i): RunDetail => $this->fixtureRun("run-{$i}", [$this->step('steady', 100)]),
            range(1, 6),
        );

        $this->assertSame([], $analyzer->analyze('flow-a', $runs));
    }
}
