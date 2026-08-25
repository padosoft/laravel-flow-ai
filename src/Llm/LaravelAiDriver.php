<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Llm;

use Laravel\Ai\Responses\AgentResponse;
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use RuntimeException;
use Throwable;

/**
 * {@see LlmClient} implementation backed by the official `laravel/ai` SDK.
 *
 * ## Why this exists next to AnthropicDriver
 *
 * `AnthropicDriver` speaks one provider's HTTP API directly, which is the right
 * amount of machinery for one provider and the wrong amount for five. Everything
 * a second provider needs — request shaping, failover, usage accounting, the
 * events the rest of this ecosystem now listens to — already exists in the SDK
 * and would otherwise be rewritten here, once per provider.
 *
 * Binding this driver instead gets, without any change to the nodes:
 *
 *  - **Every provider `laravel/ai` supports**, selected by config rather than by
 *    swapping a class.
 *  - **Failover** across providers and models, already implemented and tested
 *    upstream.
 *  - **Observability for free**: the run emits the 0.11 step and tool events, so
 *    [laravel-ai-finops] meters a flow node's spend per step, and
 *    [laravel-iam-agents] stamps the run's invocation id onto the delegation
 *    context — with nothing added to this package.
 *
 * ## What it deliberately does not do
 *
 * **Structured output is instructed, not provider-enforced.** `laravel/ai` takes
 * a schema as Laravel JsonSchema *type objects*, not as the raw JSON Schema array
 * `LlmRequest::$responseSchema` carries, and translating one into the other for
 * arbitrary schemas is a job with edge cases that would fail quietly. So the
 * schema is stated in the instructions as a contract the model must satisfy, and
 * — exactly as {@see LlmClient} already promises — the caller parses and
 * validates the text it gets back. A caller that needs the provider itself to
 * refuse a non-conforming response should bind {@see AnthropicDriver}, which
 * forces it through tool use.
 *
 * **One step, never a loop.** See {@see LaravelAiRequestAgent::maxSteps()}.
 *
 * @api
 */
final class LaravelAiDriver implements LlmClient
{
    /**
     * @param  string|null  $provider  a `laravel/ai` provider name, or null for the configured default
     */
    public function __construct(private readonly ?string $provider = null) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        $agent = new LaravelAiRequestAgent(
            $this->instructions($request),
            $request->temperature,
            $request->maxTokens,
        );

        try {
            $response = $agent->prompt(
                $request->prompt,
                provider: $this->provider,
                model: $request->model,
            );
        } catch (Throwable $exception) {
            // The flow layer expects a RuntimeException it can turn into a node
            // failure; an SDK exception type would leak the provider into the
            // node's error handling, which is what LlmClient exists to prevent.
            throw new RuntimeException(
                'laravel/ai completion failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        return new LlmResponse(
            content: $response->text,
            // The responding model can differ from the requested one after a
            // failover, and the caller costing this node needs the one that
            // actually answered.
            model: $response->meta->model ?? $request->model,
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
            stopReason: $this->stopReason($response),
        );
    }

    /**
     * The system turn, plus the response contract when the caller asked for one.
     */
    private function instructions(LlmRequest $request): string
    {
        $instructions = $request->systemPrompt ?? '';

        if ($request->responseSchema === null) {
            return $instructions;
        }

        $schema = json_encode($request->responseSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($schema === false) {
            return $instructions;
        }

        return trim($instructions."\n\n"
            ."Respond with JSON only — no prose, no code fences — conforming to this JSON Schema:\n"
            .$schema);
    }

    /**
     * The finish reason of the step that ended the run.
     *
     * A run stopped on a pending approval is reported as such rather than as a
     * normal stop: text claiming an action was taken while its approval is still
     * pending reads as success and is not one.
     */
    private function stopReason(AgentResponse $response): ?string
    {
        if ($response->pendingApprovals->isNotEmpty()) {
            return 'pending_approval';
        }

        $last = $response->steps->last();

        return $last === null ? null : $last->finishReason->value;
    }
}
