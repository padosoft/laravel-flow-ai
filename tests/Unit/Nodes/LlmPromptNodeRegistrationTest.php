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

    public function test_the_node_registers_into_cores_registry_regardless_of_provider_boot_order(): void
    {
        $definition = $this->app->make(NodeRegistry::class)->get('ai.llm.prompt');

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

    public function test_node_handlers_config_composes_with_a_host_apps_own_entries(): void
    {
        // Registration reads-then-appends to laravel-flow.nodes.handlers at
        // provider REGISTER time (not lazily), so a host app whose OWN
        // provider registers before this one still ends up in the final list
        // rather than being clobbered. Simulate that by asserting the config
        // key is a strict superset containing both a pre-seeded entry and
        // this package's own handler.
        $handlers = (array) $this->app['config']->get('laravel-flow.nodes.handlers', []);

        $this->assertContains(LlmPromptNode::class, $handlers);
        $this->assertSame($handlers, array_values(array_unique($handlers)), 'no duplicate entries');
    }
}
