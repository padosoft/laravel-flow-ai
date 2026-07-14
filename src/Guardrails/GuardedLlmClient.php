<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Guardrails;

use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use Padosoft\LaravelFlowAI\Llm\FakeDriver;
use Padosoft\LaravelFlowAI\Llm\LlmRequest;
use Padosoft\LaravelFlowAI\Llm\LlmResponse;

/**
 * Decorates a real {@see LlmClient} with a {@see PolicyEngine} check, run
 * BEFORE every delegated call — a denial throws {@see PolicyDeniedException}
 * and the wrapped client is never invoked, so a denied call has zero network
 * effect (and, in tests, zero recorded calls on a {@see FakeDriver}).
 *
 * `$nodeType`/`$targetHost` are fixed at construction: this instance guards
 * ONE specific (node type, provider) pairing — a future node type wanting
 * its own guarded LLM access gets its OWN `GuardedLlmClient` instance around
 * its own client, not a shared one, since the target host and node identity
 * are call-site properties, not something `LlmRequest` carries per call.
 *
 * @api
 */
final class GuardedLlmClient implements LlmClient
{
    public function __construct(
        private readonly LlmClient $inner,
        private readonly PolicyEngine $policy,
        private readonly string $nodeType,
        private readonly string $targetHost,
    ) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        $decision = $this->policy->authorize($this->nodeType, $this->targetHost);

        if (! $decision->allowed) {
            throw new PolicyDeniedException($decision->reason ?? 'denied by policy');
        }

        return $this->inner->complete($request);
    }
}
