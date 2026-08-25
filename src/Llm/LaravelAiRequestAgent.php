<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Llm;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/**
 * A throwaway `laravel/ai` agent carrying one {@see LlmRequest}'s options.
 *
 * `laravel/ai` declares generation options as **class attributes**
 * (`#[Temperature]`, `#[MaxTokens]`), which is the wrong granularity for a
 * driver whose options arrive per request. The seam is that
 * `TextGenerationOptions::forAgent()` looks for a **method** of the same name
 * first and only falls back to the attribute — so an agent that answers
 * `temperature()` and `maxTokens()` supplies them per call, with no subclass per
 * combination of values.
 *
 * It implements the SDK's contracts directly rather than extending
 * `AnonymousAgent`: that class takes `(string $instructions, iterable $messages,
 * iterable $tools)`, and narrowing those parameters to this class's own would
 * break the constructor's contravariance — a subclass has to accept everything
 * its parent accepts.
 *
 * @internal Constructed by {@see LaravelAiDriver}; not part of this package's API.
 */
final class LaravelAiRequestAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(
        private readonly string $instructions,
        private readonly float $temperature,
        private readonly int $maxTokens,
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    /**
     * A completion has no tools by design — see {@see maxSteps()}.
     *
     * @return iterable<mixed>
     */
    public function tools(): iterable
    {
        return [];
    }

    public function temperature(): float
    {
        return $this->temperature;
    }

    public function maxTokens(): int
    {
        return $this->maxTokens;
    }

    /**
     * One provider call, never an agent loop.
     *
     * This driver implements a *completion*: the caller is a flow node that has
     * already decided what happens next. Leaving the step budget at the SDK's
     * default would let a prompt that happened to emit a tool call turn one node
     * into a multi-step run the flow never authorised.
     */
    public function maxSteps(): int
    {
        return 1;
    }
}
