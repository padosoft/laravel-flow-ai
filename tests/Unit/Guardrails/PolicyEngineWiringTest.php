<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Guardrails;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlowAI\Guardrails\PolicyEngine;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;
use Padosoft\LaravelFlowAI\Nodes\McpClientNode;
use ReflectionProperty;

/**
 * Pins that {@see PolicyEngine} is ONE shared container singleton across
 * every AI-pack node making an outbound call — not a separate instance per
 * node type. Each gate's checks are already keyed by node type internally
 * (see {@see PolicyEngine::authorize()}), so sharing costs nothing and keeps
 * one config surface (`laravel-flow-ai.guardrails`) governing every
 * outbound call this package makes, LLM or MCP.
 */
final class PolicyEngineWiringTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowAIServiceProvider::class];
    }

    public function test_mcp_client_node_receives_the_same_policy_engine_singleton_as_the_container(): void
    {
        $containerPolicy = $this->app->make(PolicyEngine::class);
        $node = $this->app->make(McpClientNode::class);

        $property = new ReflectionProperty(McpClientNode::class, 'policy');
        $property->setAccessible(true);
        $nodePolicy = $property->getValue($node);

        $this->assertSame($containerPolicy, $nodePolicy);
    }
}
