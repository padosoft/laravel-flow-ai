<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Advisor\Analyzers;

use Padosoft\LaravelFlow\Dashboard\RunDetail;
use Padosoft\LaravelFlow\Dashboard\RunSummary;
use Padosoft\LaravelFlow\Dashboard\StepSummary;
use Padosoft\LaravelFlowAI\Advisor\Analyzers\FailureHotspotAnalyzer;
use PHPUnit\Framework\TestCase;

final class FailureHotspotAnalyzerTest extends TestCase
{
    private function step(string $name, string $status, ?string $errorClass = null, ?string $errorMessage = null): StepSummary
    {
        return new StepSummary(
            id: 1,
            runId: 'run-1',
            name: $name,
            handler: 'SomeHandler',
            sequence: 1,
            status: $status,
            errorClass: $errorClass,
            errorMessage: $errorMessage,
            durationMs: 100,
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

    public function test_flags_a_node_above_the_failure_rate_threshold_with_enough_samples(): void
    {
        $analyzer = new FailureHotspotAnalyzer(minFailureRate: 0.3, minSamples: 3);
        $runs = [
            $this->fixtureRun('run-1', [$this->step('risky', 'failed', 'RuntimeException', 'boom 1')]),
            $this->fixtureRun('run-2', [$this->step('risky', 'failed', 'RuntimeException', 'boom 2')]),
            $this->fixtureRun('run-3', [$this->step('risky', 'succeeded')]),
        ];

        $findings = $analyzer->analyze('flow-a', $runs);

        $this->assertCount(1, $findings);
        $this->assertSame('failure_hotspot', $findings[0]->type);
        $this->assertSame('risky', $findings[0]->rationale['node_id']);
        $this->assertSame(3, $findings[0]->rationale['total_runs']);
        $this->assertSame(2, $findings[0]->rationale['failed_runs']);
        $this->assertEqualsWithDelta(2 / 3, $findings[0]->rationale['failure_rate'], 0.0001);
        $this->assertSame(['boom 1', 'boom 2'], $findings[0]->rationale['sample_error_messages']);
    }

    public function test_below_the_sample_size_guard_is_not_flagged(): void
    {
        $analyzer = new FailureHotspotAnalyzer(minFailureRate: 0.3, minSamples: 3);
        $runs = [
            $this->fixtureRun('run-1', [$this->step('risky', 'failed', 'RuntimeException', 'boom')]),
        ];

        $this->assertSame([], $analyzer->analyze('flow-a', $runs));
    }

    public function test_below_the_failure_rate_threshold_is_not_flagged(): void
    {
        $analyzer = new FailureHotspotAnalyzer(minFailureRate: 0.5, minSamples: 3);
        $runs = [
            $this->fixtureRun('run-1', [$this->step('stable', 'failed', 'RuntimeException', 'boom')]),
            $this->fixtureRun('run-2', [$this->step('stable', 'succeeded')]),
            $this->fixtureRun('run-3', [$this->step('stable', 'succeeded')]),
            $this->fixtureRun('run-4', [$this->step('stable', 'succeeded')]),
        ];

        $this->assertSame([], $analyzer->analyze('flow-a', $runs));
    }
}
