<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Nodes;

use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Persistence\KeyBasedPayloadRedactor;
use Padosoft\LaravelFlowAI\Guardrails\GuardedLlmClient;
use Padosoft\LaravelFlowAI\Guardrails\PolicyEngine;
use Padosoft\LaravelFlowAI\Llm\FakeDriver;
use Padosoft\LaravelFlowAI\Llm\LlmResponse;
use Padosoft\LaravelFlowAI\Nodes\LlmPromptNode;
use PHPUnit\Framework\TestCase;

final class LlmPromptNodeTest extends TestCase
{
    private function context(array $inputs, bool $dryRun = false): NodeContext
    {
        return new NodeContext('run-1', 'definition', 'node-1', $inputs, $dryRun);
    }

    public function test_prompt_template_renders_input_variables_correctly(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"ok":true}', model: 'claude-x', promptTokens: 10, completionTokens: 5),
        ]);
        $node = new LlmPromptNode($driver);

        $node->execute($this->context([
            'template' => 'Summarize {{topic}} in {{count}} words.',
            'model' => 'claude-x',
            'variables' => ['topic' => 'flows', 'count' => 10],
        ]));

        $this->assertSame('Summarize flows in 10 words.', $driver->requests()[0]->prompt);
        $this->assertSame('claude-x', $driver->requests()[0]->model);
    }

    public function test_valid_json_object_response_succeeds(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"summary":"a flow"}', model: 'claude-x', promptTokens: 10, completionTokens: 5),
        ]);
        $node = new LlmPromptNode($driver);

        $result = $node->execute($this->context([
            'template' => 'Summarize.',
            'model' => 'claude-x',
        ]));

        $this->assertTrue($result->success);
        $this->assertSame(['summary' => 'a flow'], $result->outputs['result']);
        $this->assertCount(1, $driver->requests());
    }

    public function test_a_nested_object_in_the_response_decodes_recursively_as_an_array(): void
    {
        // A shallow (array) cast on the top-level decoded stdClass would
        // leave a NESTED object (here: "profile") as a stdClass instance
        // instead of an array — this exact bug already bit BoundedAgentNode
        // once (F-PR6) via a copy of this same decode pattern.
        $driver = new FakeDriver([
            new LlmResponse(content: '{"profile":{"name":"Ada","tags":["x","y"]}}', model: 'claude-x', promptTokens: 10, completionTokens: 5),
        ]);
        $node = new LlmPromptNode($driver);

        $result = $node->execute($this->context([
            'template' => 'Summarize.',
            'model' => 'claude-x',
        ]));

        $this->assertTrue($result->success);
        $this->assertIsArray($result->outputs['result']['profile']);
        $this->assertSame(['name' => 'Ada', 'tags' => ['x', 'y']], $result->outputs['result']['profile']);
    }

    public function test_schema_violation_retries_with_error_fed_back_then_succeeds(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: 'not json at all', model: 'claude-x', promptTokens: 10, completionTokens: 2),
            new LlmResponse(content: '{"summary":"fixed"}', model: 'claude-x', promptTokens: 12, completionTokens: 4),
        ]);
        $node = new LlmPromptNode($driver);

        $result = $node->execute($this->context([
            'template' => 'Summarize.',
            'model' => 'claude-x',
        ]));

        $this->assertTrue($result->success);
        $this->assertSame(['summary' => 'fixed'], $result->outputs['result']);
        $this->assertCount(2, $driver->requests());
        $this->assertStringNotContainsString('invalid', $driver->requests()[0]->prompt, 'first attempt has no error prefix');
        $this->assertStringContainsString('invalid', $driver->requests()[1]->prompt, 'retry prompt feeds the validation error back');
        $this->assertStringContainsString('not valid JSON', $driver->requests()[1]->prompt, 'the retry prompt carries the SPECIFIC decode failure reason, not a generic message');
    }

    public function test_retry_prompt_carries_the_specific_not_an_object_reason(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '[1,2,3]', model: 'claude-x', promptTokens: 5, completionTokens: 2),
            new LlmResponse(content: '{"ok":true}', model: 'claude-x', promptTokens: 5, completionTokens: 2),
        ]);
        $node = new LlmPromptNode($driver);

        $node->execute($this->context(['template' => 't', 'model' => 'claude-x']));

        // Distinguishes "valid JSON but the wrong shape" from "not JSON at
        // all" — a more actionable self-repair hint for the model.
        $this->assertStringContainsString('not an object', $driver->requests()[1]->prompt);
    }

    public function test_a_json_array_is_rejected_as_not_an_object(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '[1,2,3]', model: 'claude-x', promptTokens: 5, completionTokens: 2),
            new LlmResponse(content: '{"ok":true}', model: 'claude-x', promptTokens: 5, completionTokens: 2),
        ]);
        $node = new LlmPromptNode($driver);

        $result = $node->execute($this->context(['template' => 't', 'model' => 'claude-x']));

        $this->assertTrue($result->success, 'a list is rejected on attempt 1, retry succeeds on attempt 2');
        $this->assertCount(2, $driver->requests());
    }

    public function test_an_empty_json_array_is_rejected_as_not_an_object(): void
    {
        // `[]` and `{}` both decode to the same empty PHP array under
        // json_decode(..., true) — the empty-array case must not be a
        // shortcut that lets a literal JSON array bypass validation.
        $driver = new FakeDriver([
            new LlmResponse(content: '[]', model: 'claude-x', promptTokens: 5, completionTokens: 2),
            new LlmResponse(content: '{}', model: 'claude-x', promptTokens: 5, completionTokens: 2),
        ]);
        $node = new LlmPromptNode($driver);

        $result = $node->execute($this->context(['template' => 't', 'model' => 'claude-x']));

        $this->assertTrue($result->success, 'empty array rejected on attempt 1, empty object succeeds on attempt 2');
        $this->assertSame([], $result->outputs['result']);
        $this->assertCount(2, $driver->requests());
    }

    public function test_a_json_scalar_or_string_is_rejected_as_not_an_object(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '"just a string"', model: 'claude-x', promptTokens: 5, completionTokens: 2),
            new LlmResponse(content: '42', model: 'claude-x', promptTokens: 5, completionTokens: 2),
            new LlmResponse(content: '{"ok":true}', model: 'claude-x', promptTokens: 5, completionTokens: 2),
        ]);
        $node = new LlmPromptNode($driver, maxAttempts: 3);

        $result = $node->execute($this->context(['template' => 't', 'model' => 'claude-x']));

        $this->assertTrue($result->success);
        $this->assertCount(3, $driver->requests());
    }

    public function test_render_failure_returns_failed_not_an_uncaught_exception(): void
    {
        // A malformed variables value that json_encode() cannot serialize
        // (invalid UTF-8) must surface as a structured NodeResult::failed(),
        // never bubble out of execute() as an uncaught JsonException.
        $driver = new FakeDriver([]);
        $node = new LlmPromptNode($driver);

        $result = $node->execute($this->context([
            'template' => 'Value: {{bad}}',
            'model' => 'claude-x',
            'variables' => ['bad' => ["invalid utf-8 \xB1\x31"]],
        ]));

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertSame(0, $driver->requestCount(), 'the LLM is never called when rendering fails');
    }

    public function test_an_empty_json_object_is_accepted(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{}', model: 'claude-x', promptTokens: 5, completionTokens: 2),
        ]);
        $node = new LlmPromptNode($driver);

        $result = $node->execute($this->context(['template' => 't', 'model' => 'claude-x']));

        $this->assertTrue($result->success);
        $this->assertSame([], $result->outputs['result']);
    }

    public function test_business_impact_uses_the_providers_actual_model_not_the_requested_one(): void
    {
        // A provider may canonicalize/alias the requested model id or route
        // to a different one entirely — the ACTUAL model that served the
        // request (from the response) is what business_impact must report,
        // not the caller's requested string.
        $driver = new FakeDriver([
            new LlmResponse(content: '{"ok":true}', model: 'claude-3-5-sonnet-20260701', promptTokens: 5, completionTokens: 2),
        ]);
        $node = new LlmPromptNode($driver);

        $result = $node->execute($this->context(['template' => 't', 'model' => 'claude-3-5-sonnet-latest']));

        $this->assertTrue($result->success);
        $this->assertSame('claude-3-5-sonnet-20260701', $result->businessImpact['model']);
    }

    public function test_retry_cap_exhausted_returns_failed(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: 'nope', model: 'claude-x', promptTokens: 1, completionTokens: 1),
            new LlmResponse(content: 'still nope', model: 'claude-x', promptTokens: 1, completionTokens: 1),
            new LlmResponse(content: 'nope again', model: 'claude-x', promptTokens: 1, completionTokens: 1),
        ]);
        $node = new LlmPromptNode($driver, maxAttempts: 3);

        $result = $node->execute($this->context(['template' => 't', 'model' => 'claude-x']));

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('3 attempt', $result->error->getMessage());
        $this->assertCount(3, $driver->requests());
    }

    public function test_token_usage_and_cost_land_in_business_impact(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: 'bad', model: 'claude-x', promptTokens: 10, completionTokens: 5),
            new LlmResponse(content: '{"ok":true}', model: 'claude-x', promptTokens: 20, completionTokens: 8),
        ]);
        $node = new LlmPromptNode($driver);

        $result = $node->execute($this->context(['template' => 't', 'model' => 'claude-x']));

        $this->assertTrue($result->success);
        $this->assertSame('claude-x', $result->businessImpact['model']);
        // tokens accumulate across BOTH attempts (real spend regardless of retries)
        $this->assertSame(30, $result->businessImpact['tokens']['prompt']);
        $this->assertSame(13, $result->businessImpact['tokens']['completion']);
        $this->assertSame(43, $result->businessImpact['tokens']['total']);
    }

    public function test_zero_or_negative_max_attempts_is_rejected_at_construction(): void
    {
        $driver = new FakeDriver([]);

        $this->expectException(\InvalidArgumentException::class);

        new LlmPromptNode($driver, maxAttempts: 0);
    }

    public function test_outbound_payload_is_redacted(): void
    {
        // F-PR3: a redacted-list KEY wired into a template variable must
        // never reach the rendered prompt actually sent to the LLM client —
        // asserted on the payload the FAKE client actually received.
        $driver = new FakeDriver([
            new LlmResponse(content: '{"ok":true}', model: 'claude-x', promptTokens: 5, completionTokens: 2),
        ]);
        $redactor = new KeyBasedPayloadRedactor(enabled: true, keys: ['secret'], replacement: '[redacted]');
        $node = new LlmPromptNode($driver, redactor: $redactor);

        $node->execute($this->context([
            'template' => 'The secret is: {{secret}}. Topic: {{topic}}.',
            'model' => 'claude-x',
            'variables' => ['secret' => 'sk-super-sensitive-123', 'topic' => 'flows'],
        ]));

        $sentPrompt = $driver->requests()[0]->prompt;
        $this->assertStringNotContainsString('sk-super-sensitive-123', $sentPrompt, 'the secret value never reaches the outbound request');
        $this->assertStringContainsString('[redacted]', $sentPrompt);
        $this->assertStringContainsString('flows', $sentPrompt, 'a non-redacted variable still renders normally');
    }

    public function test_no_redactor_means_variables_pass_through_unchanged(): void
    {
        // Direct construction (bypassing the container) with no redactor is
        // a valid, deliberate opt-out — e.g. isolated node-logic tests that
        // don't care about redaction at all (every OTHER test in this file).
        $driver = new FakeDriver([
            new LlmResponse(content: '{"ok":true}', model: 'claude-x', promptTokens: 5, completionTokens: 2),
        ]);
        $node = new LlmPromptNode($driver);

        $node->execute($this->context([
            'template' => 'Value: {{secret}}',
            'model' => 'claude-x',
            'variables' => ['secret' => 'not-actually-redacted-without-a-redactor'],
        ]));

        $this->assertStringContainsString('not-actually-redacted-without-a-redactor', $driver->requests()[0]->prompt);
    }

    public function test_a_disabled_redactor_leaves_variables_unchanged(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"ok":true}', model: 'claude-x', promptTokens: 5, completionTokens: 2),
        ]);
        $redactor = new KeyBasedPayloadRedactor(enabled: false, keys: ['secret']);
        $node = new LlmPromptNode($driver, redactor: $redactor);

        $node->execute($this->context([
            'template' => 'Value: {{secret}}',
            'model' => 'claude-x',
            'variables' => ['secret' => 'still-here-when-redaction-is-disabled'],
        ]));

        $this->assertStringContainsString('still-here-when-redaction-is-disabled', $driver->requests()[0]->prompt);
    }

    public function test_a_policy_denial_returns_failed_immediately_not_uncaught(): void
    {
        // Round-1 review (Codex): PolicyDeniedException thrown by a guarded
        // client must be caught by the node itself and mapped to
        // NodeResult::failed(), not left to escape execute() uncaught —
        // this node is tested in isolation here, with NO core NodeExecutor
        // in the call stack to catch it as an outer safety net.
        $inner = new FakeDriver([
            new LlmResponse(content: '{"ok":true}', model: 'claude-x', promptTokens: 1, completionTokens: 1),
        ]);
        $denyingPolicy = new PolicyEngine(allowedNodeTypes: ['something-else']);
        $guarded = new GuardedLlmClient($inner, $denyingPolicy, nodeType: 'ai.llm.prompt', targetHost: 'api.anthropic.com');
        $node = new LlmPromptNode($guarded);

        $result = $node->execute($this->context(['template' => 't', 'model' => 'claude-x']));

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertSame(0, $inner->requestCount(), 'the denial happened before the wrapped client was ever reached');
    }

    public function test_a_policy_denial_is_not_retried_by_the_schema_loop(): void
    {
        $inner = new FakeDriver([]);
        $denyingPolicy = new PolicyEngine(allowedNodeTypes: ['something-else']);
        $guarded = new GuardedLlmClient($inner, $denyingPolicy, nodeType: 'ai.llm.prompt', targetHost: 'api.anthropic.com');
        $node = new LlmPromptNode($guarded, maxAttempts: 3);

        $node->execute($this->context(['template' => 't', 'model' => 'claude-x']));

        $this->assertSame(0, $inner->requestCount(), 'a policy denial fails immediately — it is never retried across attempts');
    }

    public function test_dry_run_never_calls_the_llm_client(): void
    {
        $driver = new FakeDriver([]);
        $node = new LlmPromptNode($driver);

        $result = $node->execute($this->context(['template' => 't', 'model' => 'claude-x'], dryRun: true));

        $this->assertTrue($result->dryRunSkipped);
        $this->assertSame(0, $driver->requestCount());
    }
}
