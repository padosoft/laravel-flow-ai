<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Nodes;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;
use Padosoft\LaravelFlowAI\Nodes\McpClientNode;

final class McpClientNodeRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowAIServiceProvider::class];
    }

    public function test_the_node_registers_into_cores_registry(): void
    {
        $registry = $this->app->make(NodeRegistry::class);
        $definition = $registry->get('ai.mcp.tool');

        $this->assertSame(McpClientNode::class, $definition->handlerClass);
        $this->assertSame('ai', $definition->category);

        $inputKeys = array_map(static fn ($port) => $port->key, $definition->inputs);
        $this->assertContains('command', $inputKeys);
        $this->assertContains('args', $inputKeys);
        $this->assertContains('tool', $inputKeys);
        $this->assertContains('arguments', $inputKeys);

        $result = $definition->output('result');
        $this->assertNotNull($result);
        $this->assertSame(PortType::Json, $result->type);
    }

    public function test_node_handlers_config_has_no_duplicate_entries(): void
    {
        $handlers = (array) $this->app['config']->get('laravel-flow.nodes.handlers', []);

        $this->assertContains(McpClientNode::class, $handlers);
        $this->assertSame($handlers, array_values(array_unique($handlers)), 'no duplicate entries');
    }

    public function test_the_node_is_container_resolvable_with_a_real_transport_factory(): void
    {
        // Confirms McpTransportFactory::class is actually bound — a container
        // build of McpClientNode would fail with a BindingResolutionException
        // otherwise, since the parameter type is an interface.
        $node = $this->app->make(McpClientNode::class);

        $this->assertInstanceOf(McpClientNode::class, $node);
    }
}
