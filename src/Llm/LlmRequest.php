<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Llm;

use Padosoft\LaravelFlowAI\Contracts\LlmClient;

/**
 * A single completion request. `$prompt` is the user-turn content;
 * `$systemPrompt` is an optional system-turn instruction. `$responseSchema`
 * is an optional JSON Schema (array form) hint instructing the provider to
 * return structured output shaped accordingly — validating the ACTUAL
 * response against a node's declared output ports is the caller's job (the
 * LLM prompt node), not this package's {@see LlmClient}
 * layer, which only carries the hint through to the provider and returns
 * whatever text came back.
 *
 * @api
 */
final readonly class LlmRequest
{
    /**
     * @param  array<string, mixed>|null  $responseSchema
     */
    public function __construct(
        public string $prompt,
        public string $model,
        public ?string $systemPrompt = null,
        public float $temperature = 1.0,
        public int $maxTokens = 1024,
        public ?array $responseSchema = null,
    ) {}
}
