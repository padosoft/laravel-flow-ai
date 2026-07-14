<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Advisor\Analyzers;

use Padosoft\LaravelFlow\Dashboard\RunDetail;
use Padosoft\LaravelFlow\Dashboard\RunSummary;
use Padosoft\LaravelFlow\Dashboard\StepSummary;
use Padosoft\LaravelFlowAI\Advisor\Analyzers\UnusedToolAnalyzer;
use PHPUnit\Framework\TestCase;

final class UnusedToolAnalyzerTest extends TestCase
{
    public function test_flags_an_exposed_flow_with_zero_runs(): void
    {
        $analyzer = new UnusedToolAnalyzer(['exposed-but-idle']);

        $findings = $analyzer->analyze('exposed-but-idle', []);

        $this->assertCount(1, $findings);
        $this->assertSame('unused_tool', $findings[0]->type);
        $this->assertSame('exposed-but-idle', $findings[0]->rationale['definition_name']);
    }

    public function test_an_exposed_flow_with_runs_is_not_flagged(): void
    {
        $analyzer = new UnusedToolAnalyzer(['exposed-and-active']);
        $step = new StepSummary(1, 'run-1', 'a', 'H', 1, 'succeeded', null, null, 10, null, null);
        $run = new RunDetail(
            run: new RunSummary('run-1', 'exposed-and-active', 'succeeded', false, null, false, null, null, null, null, null, null, null),
            steps: [$step],
            audit: [],
            approvals: [],
            webhookOutbox: [],
            input: null,
            output: null,
            businessImpact: null,
        );

        $this->assertSame([], $analyzer->analyze('exposed-and-active', [$run]));
    }

    public function test_a_non_exposed_flow_with_zero_runs_is_not_flagged(): void
    {
        $analyzer = new UnusedToolAnalyzer(['some-other-flow']);

        $this->assertSame([], $analyzer->analyze('never-exposed', []));
    }
}
