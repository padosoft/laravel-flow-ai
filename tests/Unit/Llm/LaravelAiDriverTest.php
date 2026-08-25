<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Llm;

use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\Usage;
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use Padosoft\LaravelFlowAI\Llm\LaravelAiDriver;
use Padosoft\LaravelFlowAI\Llm\LaravelAiRequestAgent;
use Padosoft\LaravelFlowAI\Llm\LlmRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Everything here runs without touching a provider: `complete()` itself needs a
 * configured `laravel/ai` provider, and this suite forbids real network calls.
 * What is tested is the part this package owns — the seam that carries
 * per-request options into the SDK, and the two mappings on the way back out.
 */
final class LaravelAiDriverTest extends TestCase
{
    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(LaravelAiDriver::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(new LaravelAiDriver, ...$arguments);
    }

    private function response(?Collection $steps = null, ?Collection $pendingApprovals = null): AgentResponse
    {
        $response = new AgentResponse('inv_1', 'the answer', new Usage, new Meta('openai', 'gpt-4o-mini'));

        if ($steps !== null) {
            $response = $response->withSteps($steps);
        }

        return $pendingApprovals === null ? $response : $response->withPendingApprovals($pendingApprovals);
    }

    private function step(FinishReason $reason): Step
    {
        return new Step('text', [], [], $reason, new Usage, new Meta('openai', 'gpt-4o-mini'));
    }

    public function test_the_driver_satisfies_the_package_contract(): void
    {
        $this->assertInstanceOf(LlmClient::class, new LaravelAiDriver);
    }

    public function test_per_request_options_reach_the_sdk(): void
    {
        $agent = new LaravelAiRequestAgent('be terse', 0.2, 512);

        $options = TextGenerationOptions::forAgent($agent);

        // laravel/ai declares these as CLASS attributes; the only reason a driver
        // can vary them per request is that forAgent() reads a method of the same
        // name first. If that ever changes upstream, this fails and the driver
        // silently reverting to provider defaults is caught here rather than in
        // somebody's bill.
        $this->assertSame(0.2, $options->temperature);
        $this->assertSame(512, $options->maxTokens);
    }

    public function test_a_node_is_one_completion_not_an_agent_loop(): void
    {
        $options = TextGenerationOptions::forAgent(new LaravelAiRequestAgent('', 1.0, 1024));

        // A prompt that happens to emit a tool call must not turn one node into a
        // multi-step run the flow never authorised.
        $this->assertSame(1, $options->maxSteps);
        $this->assertSame([], (new LaravelAiRequestAgent('', 1.0, 1024))->tools());
    }

    public function test_the_system_prompt_is_passed_through_untouched_without_a_schema(): void
    {
        $instructions = $this->invoke('instructions', new LlmRequest(
            prompt: 'hi',
            model: 'gpt-4o-mini',
            systemPrompt: 'You are terse.',
        ));

        $this->assertSame('You are terse.', $instructions);
    }

    public function test_a_response_schema_becomes_a_stated_contract(): void
    {
        $instructions = $this->invoke('instructions', new LlmRequest(
            prompt: 'hi',
            model: 'gpt-4o-mini',
            systemPrompt: 'You are terse.',
            responseSchema: ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]],
        ));

        $this->assertStringContainsString('You are terse.', $instructions);
        $this->assertStringContainsString('JSON Schema', $instructions);
        $this->assertStringContainsString('"ok"', $instructions);
    }

    public function test_the_stop_reason_is_the_finish_reason_of_the_last_step(): void
    {
        $reason = $this->invoke('stopReason', $this->response(new Collection([
            $this->step(FinishReason::ToolCalls),
            $this->step(FinishReason::Stop),
        ])));

        $this->assertSame(FinishReason::Stop->value, $reason);
    }

    public function test_a_pending_approval_beats_the_last_step(): void
    {
        $reason = $this->invoke(
            'stopReason',
            $this->response(
                new Collection([$this->step(FinishReason::Stop)]),
                new Collection([new PendingApproval('call_1', 'refund_order', [])]),
            ),
        );

        // Text claiming an action was taken while its approval is still pending
        // reads as success and is not one.
        $this->assertSame('pending_approval', $reason);
    }

    public function test_a_response_with_no_steps_reports_no_stop_reason(): void
    {
        $this->assertNull($this->invoke('stopReason', $this->response()));
    }
}
