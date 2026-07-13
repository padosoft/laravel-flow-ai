<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Guardrails;

use Padosoft\LaravelFlow\Node\NodeResult;

/**
 * The outcome of a single {@see PolicyEngine::authorize()} check: either
 * allowed, or denied with a human-readable reason a caller can surface in a
 * failed {@see NodeResult} / log line.
 *
 * @api
 */
final readonly class PolicyDecision
{
    private function __construct(
        public bool $allowed,
        public ?string $reason,
    ) {}

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
