<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Nodes;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;
use Padosoft\LaravelFlowAI\Nodes\LlmPromptNode;

final class LlmPromptNodeRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowAIServiceProvider::class];
    }

    private function assertNodeRegisteredCorrectly(NodeRegistry $registry): void
    {
        $definition = $registry->get('ai.llm.prompt');

        $this->assertSame(LlmPromptNode::class, $definition->handlerClass);
        $this->assertSame('ai', $definition->category);

        $inputKeys = array_map(static fn ($port) => $port->key, $definition->inputs);
        $this->assertContains('template', $inputKeys);
        $this->assertContains('model', $inputKeys);
        $this->assertContains('variables', $inputKeys);

        $result = $definition->output('result');
        $this->assertNotNull($result);
        $this->assertSame(PortType::Json, $result->type);
    }

    public function test_the_node_registers_into_cores_registry_with_core_provider_first(): void
    {
        $this->assertNodeRegisteredCorrectly($this->app->make(NodeRegistry::class));
    }

    public function test_registration_does_not_clobber_cores_own_config_or_builtin_nodes(): void
    {
        // Real regression this pins: registerNodeHandlers() reads-then-writes
        // laravel-flow.nodes.handlers. If that write happened during THIS
        // package's register() (rather than boot()) and this package's
        // provider was listed before core's, core's later shallow
        // mergeConfigFrom('laravel-flow') would treat the whole `nodes` key
        // as already-set and skip merging its own defaults under it —
        // silently dropping sibling keys like `nodes.discovery` and, in a
        // real app, any node handlers a host app itself registered before
        // this package. Assert BOTH survive: core's own built-in node type
        // (flow.merge, registered via BUILTIN_NODE_HANDLERS, independent of
        // the config path) AND the nodes.discovery config key.
        $registry = $this->app->make(NodeRegistry::class);

        $this->assertNodeRegisteredCorrectly($registry);
        $this->assertTrue($registry->has('flow.merge'), "core's own built-in node must still resolve");
        $this->assertIsArray($this->app['config']->get('laravel-flow.nodes.discovery'), 'core config sibling key must survive the merge');
    }

    public function test_node_handlers_config_has_no_duplicate_entries(): void
    {
        $handlers = (array) $this->app['config']->get('laravel-flow.nodes.handlers', []);

        $this->assertContains(LlmPromptNode::class, $handlers);
        $this->assertSame($handlers, array_values(array_unique($handlers)), 'no duplicate entries');
    }
}

/**
 * Same assertions, but with THIS package's provider listed BEFORE core's —
 * the actual failure mode the boot()-timing fix guards against (see the
 * class docblock on {@see LaravelFlowAIServiceProvider::registerNodeHandlers()}).
 * Laravel guarantees every provider's register() completes before any
 * provider's boot() starts, so this order must produce an IDENTICAL result.
 */
final class LlmPromptNodeRegistrationProviderOrderReversedTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowAIServiceProvider::class, LaravelFlowServiceProvider::class];
    }

    public function test_registration_survives_with_the_ai_provider_listed_first(): void
    {
        $registry = $this->app->make(NodeRegistry::class);

        $definition = $registry->get('ai.llm.prompt');
        $this->assertSame(LlmPromptNode::class, $definition->handlerClass);

        $this->assertTrue($registry->has('flow.merge'), "core's own built-in node must still resolve");
        $this->assertIsArray($this->app['config']->get('laravel-flow.nodes.discovery'), 'core config sibling key must survive the merge');
    }
}
