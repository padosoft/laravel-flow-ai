<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Guardrails;

use Padosoft\LaravelFlowAI\Guardrails\GuardedLlmClient;
use Padosoft\LaravelFlowAI\Guardrails\PolicyDeniedException;
use Padosoft\LaravelFlowAI\Guardrails\PolicyEngine;
use Padosoft\LaravelFlowAI\Llm\FakeDriver;
use Padosoft\LaravelFlowAI\Llm\LlmRequest;
use Padosoft\LaravelFlowAI\Llm\LlmResponse;
use PHPUnit\Framework\TestCase;

final class GuardedLlmClientTest extends TestCase
{
    public function test_policy_denial_blocks_before_any_network_call(): void
    {
        $inner = new FakeDriver([
            new LlmResponse(content: '{"ok":true}', model: 'claude-x', promptTokens: 1, completionTokens: 1),
        ]);
        $policy = new PolicyEngine(allowedNodeTypes: ['ai.other']);
        $guarded = new GuardedLlmClient($inner, $policy, nodeType: 'ai.llm.prompt', targetHost: 'api.anthropic.com');

        try {
            $guarded->complete(new LlmRequest(prompt: 'hi', model: 'claude-x'));
            $this->fail('expected PolicyDeniedException');
        } catch (PolicyDeniedException $e) {
            $this->assertStringContainsString('ai.llm.prompt', $e->getMessage());
        }

        $this->assertSame(0, $inner->requestCount(), 'the wrapped client was NEVER invoked when policy denies');
    }

    public function test_allowed_call_delegates_to_the_inner_client(): void
    {
        $inner = new FakeDriver([
            new LlmResponse(content: '{"ok":true}', model: 'claude-x', promptTokens: 3, completionTokens: 2),
        ]);
        $policy = new PolicyEngine; // unconfigured = permissive
        $guarded = new GuardedLlmClient($inner, $policy, nodeType: 'ai.llm.prompt', targetHost: 'api.anthropic.com');

        $response = $guarded->complete(new LlmRequest(prompt: 'hi', model: 'claude-x'));

        $this->assertSame('{"ok":true}', $response->content);
        $this->assertSame(1, $inner->requestCount());
    }

    public function test_egress_denial_blocks_before_any_network_call(): void
    {
        $inner = new FakeDriver([
            new LlmResponse(content: '{"ok":true}', model: 'claude-x', promptTokens: 1, completionTokens: 1),
        ]);
        $policy = new PolicyEngine(egressAllowlist: ['some-other-host.example.com']);
        $guarded = new GuardedLlmClient($inner, $policy, nodeType: 'ai.llm.prompt', targetHost: 'api.anthropic.com');

        try {
            $guarded->complete(new LlmRequest(prompt: 'hi', model: 'claude-x'));
            $this->fail('expected PolicyDeniedException');
        } catch (PolicyDeniedException $e) {
            $this->assertStringContainsString('api.anthropic.com', $e->getMessage());
        }

        $this->assertSame(0, $inner->requestCount());
    }
}
