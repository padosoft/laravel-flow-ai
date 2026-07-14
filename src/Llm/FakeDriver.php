<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Llm;

use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use RuntimeException;

/**
 * Deterministic, no-network {@see LlmClient} for tests (and, if a host
 * application binds it explicitly, local development without provider
 * credentials). Constructed with a queue of canned responses returned in
 * order; every request is recorded so a test can assert what was actually
 * sent (prompt, schema hint, model, etc.) without a network fake.
 *
 * @api
 */
final class FakeDriver implements LlmClient
{
    /** @var list<LlmResponse> */
    private array $queue;

    /** @var list<LlmRequest> */
    private array $requests = [];

    /**
     * @param  list<LlmResponse>  $responses  returned in order, one per call to complete()
     */
    public function __construct(array $responses = [])
    {
        $this->queue = $responses;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            throw new RuntimeException('FakeDriver has no more canned responses queued.');
        }

        return array_shift($this->queue);
    }

    /**
     * @return list<LlmRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }
}
