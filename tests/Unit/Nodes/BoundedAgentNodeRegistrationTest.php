<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Nodes;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;
use Padosoft\LaravelFlowAI\Nodes\BoundedAgentNode;
use ReflectionClass;

final class BoundedAgentNodeRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowAIServiceProvider::class];
    }

    public function test_the_node_registers_into_cores_registry(): void
    {
        $registry = $this->app->make(NodeRegistry::class);
        $definition = $registry->get('ai.agent.bounded');

        $this->assertSame(BoundedAgentNode::class, $definition->handlerClass);
        $this->assertSame('ai', $definition->category);

        $inputKeys = array_map(static fn ($port) => $port->key, $definition->inputs);
        $this->assertContains('task', $inputKeys);
        $this->assertContains('model', $inputKeys);
        $this->assertContains('command', $inputKeys);

        $result = $definition->output('result');
        $this->assertNotNull($result);
        $this->assertSame(PortType::Json, $result->type);
    }

    public function test_node_handlers_config_has_no_duplicate_entries(): void
    {
        $handlers = (array) $this->app['config']->get('laravel-flow.nodes.handlers', []);

        $this->assertContains(BoundedAgentNode::class, $handlers);
        $this->assertSame($handlers, array_values(array_unique($handlers)), 'no duplicate entries');
    }

    public function test_the_node_is_container_resolvable_with_config_driven_budgets(): void
    {
        $this->app['config']->set('laravel-flow-ai.agent.allowed_tools', ['echo-flow']);
        $this->app['config']->set('laravel-flow-ai.agent.max_iterations', 7);
        $this->app['config']->set('laravel-flow-ai.agent.max_total_tokens', 9000);

        $node = $this->app->make(BoundedAgentNode::class);

        $this->assertInstanceOf(BoundedAgentNode::class, $node);
    }

    public function test_the_agent_gets_its_own_guarded_llm_client_not_the_prompt_nodes_shared_singleton(): void
    {
        // A shared LlmClient::class singleton would authorize/rate-limit
        // this node's calls under 'ai.llm.prompt' instead of its own
        // 'ai.agent.bounded' identity, letting a host's per-node-type
        // guardrails silently misattribute one node's calls to the other.
        $sharedPromptClient = $this->app->make(LlmClient::class);

        $agent = $this->app->make(BoundedAgentNode::class);
        $agentClientProperty = (new ReflectionClass($agent))->getProperty('client');
        $agentClient = $agentClientProperty->getValue($agent);

        $this->assertNotSame($sharedPromptClient, $agentClient);
    }
}
