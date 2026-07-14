<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Contracts\RunNodeRepository;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;

final class FlowAdvisorCommandsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowAIServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.nodes.handlers', [CommandFixtureNode::class]);
        $app['config']->set('laravel-flow-ai.advisor.min_samples', 3);
        $app['config']->set('laravel-flow-ai.advisor.min_failure_rate', 0.3);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../../../../vendor/padosoft/laravel-flow/database/migrations');
    }

    private function seedFlakyFlow(string $name): void
    {
        $graph = new GraphDefinition([new GraphNode('a', 'test.command_fixture')], []);
        $stored = $this->app->make(DefinitionRepository::class)->createDraft($name, $graph);
        $this->app->make(DefinitionRepository::class)->publish($name, $stored->version);

        $runs = $this->app->make(RunRepository::class);
        $nodes = $this->app->make(RunNodeRepository::class);

        foreach (['failed', 'failed', 'succeeded'] as $i => $status) {
            $runId = "{$name}-run-{$i}";
            $runs->create(['id' => $runId, 'definition_name' => $name, 'status' => $status === 'failed' ? 'failed' : 'succeeded', 'dry_run' => false, 'compensated' => false]);
            $nodes->createOrUpdate($runId, 'a', [
                'node_type' => 'test.command_fixture',
                'handler' => CommandFixtureNode::class,
                'sequence' => 1,
                'status' => $status,
                'error_class' => $status === 'failed' ? 'RuntimeException' : null,
                'duration_ms' => 50,
                'attempts' => 1,
                'dry_run_skipped' => false,
            ]);
        }
    }

    public function test_flow_improve_emits_rationale_and_a_draft_version_id(): void
    {
        $this->seedFlakyFlow('improve-target');

        $exitCode = Artisan::call('flow:improve', ['flow' => 'improve-target']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('failure_hotspot', $output);
        $this->assertStringContainsString('improve-target@2', $output);
    }

    public function test_flow_suggest_emits_rationale_and_a_draft_version_id(): void
    {
        $this->seedFlakyFlow('suggest-target');

        $exitCode = Artisan::call('flow:suggest');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('failure_hotspot', $output);
        $this->assertStringContainsString('suggest-target@2', $output);
    }

    public function test_flow_improve_reports_no_suggestions_gracefully(): void
    {
        $graph = new GraphDefinition([new GraphNode('a', 'test.command_fixture')], []);
        $stored = $this->app->make(DefinitionRepository::class)->createDraft('quiet-flow', $graph);
        $this->app->make(DefinitionRepository::class)->publish('quiet-flow', $stored->version);

        $exitCode = Artisan::call('flow:improve', ['flow' => 'quiet-flow']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No suggestions found', $output);
    }
}

#[FlowNode(type: 'test.command_fixture', category: 'testing')]
final class CommandFixtureNode implements FlowNodeHandler
{
    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::success([]);
    }
}
