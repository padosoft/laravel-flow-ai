<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Guardrails;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;
use Padosoft\LaravelFlowAI\Nodes\LlmPromptNode;
use ReflectionProperty;

/**
 * Pins the explicit design constraint from the Macro F plan's grounding
 * notes: "one redaction policy per application, not two divergent ones" —
 * a container-built {@see LlmPromptNode} must receive the EXACT redactor
 * instance the host app configured for core, never a separately-instantiated
 * AI-pack-local redactor with potentially different config.
 */
final class PayloadRedactorWiringTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowAIServiceProvider::class];
    }

    public function test_llm_prompt_node_receives_the_same_redactor_instance_core_bound(): void
    {
        $coreRedactor = $this->app->make(PayloadRedactor::class);
        $node = $this->app->make(LlmPromptNode::class);

        $property = new ReflectionProperty(LlmPromptNode::class, 'redactor');
        $property->setAccessible(true);
        $nodeRedactor = $property->getValue($node);

        $this->assertSame($coreRedactor, $nodeRedactor, 'the node must use the SAME bound redactor instance, not a separately-constructed one');
    }
}
