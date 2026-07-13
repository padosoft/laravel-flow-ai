<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Nodes;

use Padosoft\LaravelFlow\Node\NodeContext;
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

    public function test_dry_run_never_calls_the_llm_client(): void
    {
        $driver = new FakeDriver([]);
        $node = new LlmPromptNode($driver);

        $result = $node->execute($this->context(['template' => 't', 'model' => 'claude-x'], dryRun: true));

        $this->assertTrue($result->dryRunSkipped);
        $this->assertSame(0, $driver->requestCount());
    }
}
