<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Llm;

use Padosoft\LaravelFlowAI\Contracts\LlmClient;

/**
 * The result of one {@see LlmClient::complete()}
 * call. `$content` is the raw completion text — a caller expecting
 * structured output via `LlmRequest::$responseSchema` is responsible for
 * parsing/validating it; this package never assumes the response is valid
 * JSON. Token usage is always present (a driver whose provider doesn't
 * report it must supply its own best value, never omit the fields) so a
 * caller can always project cost into `NodeResult::$businessImpact` without
 * a null check.
 *
 * @api
 */
final readonly class LlmResponse
{
    public function __construct(
        public string $content,
        public string $model,
        public int $promptTokens,
        public int $completionTokens,
        public ?string $stopReason = null,
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
