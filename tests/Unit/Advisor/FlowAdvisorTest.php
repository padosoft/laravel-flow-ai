<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Advisor;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Contracts\RunNodeRepository;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Dashboard\FlowDashboardReadModel;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Graph\StoredDefinition;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Persistence\KeyBasedPayloadRedactor;
use Padosoft\LaravelFlowAI\Advisor\Analyzers\FailureHotspotAnalyzer;
use Padosoft\LaravelFlowAI\Advisor\FlowAdvisor;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;

final class FlowAdvisorTest extends TestCase
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
        $app['config']->set('laravel-flow.nodes.handlers', [AdvisorFixtureNode::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../../../vendor/padosoft/laravel-flow/database/migrations');
    }

    private function publishFlow(string $name): void
    {
        $graph = new GraphDefinition([new GraphNode('a', 'test.advisor_fixture')], []);
        $stored = $this->app->make(DefinitionRepository::class)->createDraft($name, $graph);
        $this->app->make(DefinitionRepository::class)->publish($name, $stored->version);
    }

    /**
     * @param  list<array{status: string, errorClass?: string, errorMessage?: string}>  $nodeRuns  one entry per fabricated run for node id "a"
     */
    private function seedRuns(string $definitionName, array $nodeRuns): void
    {
        $runs = $this->app->make(RunRepository::class);
        $nodes = $this->app->make(RunNodeRepository::class);

        foreach ($nodeRuns as $i => $nodeRun) {
            $runId = "{$definitionName}-run-{$i}";

            $runs->create([
                'id' => $runId,
                'definition_name' => $definitionName,
                'status' => $nodeRun['status'] === 'failed' ? 'failed' : 'succeeded',
                'dry_run' => false,
                'compensated' => false,
            ]);

            $nodes->createOrUpdate($runId, 'a', [
                'node_type' => 'test.advisor_fixture',
                'handler' => AdvisorFixtureNode::class,
                'sequence' => 1,
                'status' => $nodeRun['status'],
                'error_class' => $nodeRun['errorClass'] ?? null,
                'error_message' => $nodeRun['errorMessage'] ?? null,
                'duration_ms' => 100,
                'attempts' => 1,
                'dry_run_skipped' => false,
            ]);
        }
    }

    private function advisor(array $analyzers, ?KeyBasedPayloadRedactor $redactor = null): FlowAdvisor
    {
        return new FlowAdvisor(
            readModel: $this->app->make(FlowDashboardReadModel::class),
            definitions: $this->app->make(DefinitionRepository::class),
            analyzers: $analyzers,
            exposedFlowNames: [],
            redactor: $redactor,
        );
    }

    public function test_suggestions_are_always_drafts_never_auto_published(): void
    {
        $this->publishFlow('flaky-flow');
        $this->seedRuns('flaky-flow', [
            ['status' => 'failed', 'errorClass' => 'RuntimeException', 'errorMessage' => 'boom 1'],
            ['status' => 'failed', 'errorClass' => 'RuntimeException', 'errorMessage' => 'boom 2'],
            ['status' => 'succeeded'],
        ]);
        $advisor = $this->advisor([new FailureHotspotAnalyzer(minFailureRate: 0.3, minSamples: 3)]);

        $suggestions = $advisor->improve('flaky-flow');

        $this->assertNotEmpty($suggestions);

        foreach ($suggestions as $suggestion) {
            $stored = $this->app->make(DefinitionRepository::class)->find('flaky-flow', $suggestion->draftVersion);
            $this->assertSame(StoredDefinition::STATUS_DRAFT, $stored->status);
        }

        // The originally-published version must still be the latest
        // PUBLISHED one — the suggestion draft never promoted itself.
        $publishedLatest = $this->app->make(DefinitionRepository::class)->latest('flaky-flow', StoredDefinition::STATUS_PUBLISHED);
        $this->assertSame(1, $publishedLatest->version);
    }

    public function test_history_payloads_pass_the_redaction_gate(): void
    {
        $this->publishFlow('secret-flow');
        $this->seedRuns('secret-flow', [
            ['status' => 'failed', 'errorClass' => 'RuntimeException', 'errorMessage' => 'db password sk-super-secret rejected'],
            ['status' => 'failed', 'errorClass' => 'RuntimeException', 'errorMessage' => 'db password sk-super-secret rejected'],
            ['status' => 'succeeded'],
        ]);
        $redactor = new KeyBasedPayloadRedactor(enabled: true, keys: ['sample_error_messages'], replacement: '[redacted]');
        $advisor = $this->advisor([new FailureHotspotAnalyzer(minFailureRate: 0.3, minSamples: 3)], $redactor);

        $suggestions = $advisor->improve('secret-flow');

        $this->assertNotEmpty($suggestions);
        $encoded = json_encode(array_map(
            static fn ($s) => $s->finding->rationale,
            $suggestions,
        ), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('sk-super-secret', $encoded);

        // The persisted DRAFT's embedded rationale must be equally clean —
        // redaction happens before the finding ever reaches the draft, not
        // only in the in-memory Suggestion object.
        $stored = $this->app->make(DefinitionRepository::class)->find('secret-flow', $suggestions[0]->draftVersion);
        $this->assertStringNotContainsString('sk-super-secret', json_encode($stored->graph, JSON_THROW_ON_ERROR));
    }

    public function test_improve_returns_an_empty_list_when_no_analyzer_finds_anything(): void
    {
        $this->publishFlow('clean-flow');
        $this->seedRuns('clean-flow', [
            ['status' => 'succeeded'],
            ['status' => 'succeeded'],
            ['status' => 'succeeded'],
        ]);
        $advisor = $this->advisor([new FailureHotspotAnalyzer(minFailureRate: 0.3, minSamples: 3)]);

        $this->assertSame([], $advisor->improve('clean-flow'));
    }

    public function test_suggest_scans_every_candidate_flow(): void
    {
        $this->publishFlow('flaky-flow-a');
        $this->publishFlow('flaky-flow-b');
        $this->seedRuns('flaky-flow-a', [
            ['status' => 'failed', 'errorClass' => 'RuntimeException'],
            ['status' => 'failed', 'errorClass' => 'RuntimeException'],
            ['status' => 'succeeded'],
        ]);
        $this->seedRuns('flaky-flow-b', [
            ['status' => 'failed', 'errorClass' => 'RuntimeException'],
            ['status' => 'failed', 'errorClass' => 'RuntimeException'],
            ['status' => 'succeeded'],
        ]);
        $advisor = $this->advisor([new FailureHotspotAnalyzer(minFailureRate: 0.3, minSamples: 3)]);

        $suggestions = $advisor->suggest();

        $names = array_unique(array_map(static fn ($s) => $s->definitionName, $suggestions));
        sort($names);
        $this->assertSame(['flaky-flow-a', 'flaky-flow-b'], $names);
    }
}

#[FlowNode(type: 'test.advisor_fixture', category: 'testing')]
final class AdvisorFixtureNode implements FlowNodeHandler
{
    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::success([]);
    }
}
