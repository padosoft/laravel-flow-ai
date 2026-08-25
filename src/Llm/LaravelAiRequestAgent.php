<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Llm;

use Laravel\Ai\AnonymousAgent;

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
 * @internal Constructed by {@see LaravelAiDriver}; not part of this package's API.
 */
final class LaravelAiRequestAgent extends AnonymousAgent
{
    public function __construct(
        string $instructions,
        private readonly float $temperature,
        private readonly int $maxTokens,
    ) {
        parent::__construct($instructions, [], []);
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
