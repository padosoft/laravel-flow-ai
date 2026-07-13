<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Contracts;

use Padosoft\LaravelFlowAI\Llm\LlmRequest;
use Padosoft\LaravelFlowAI\Llm\LlmResponse;

/**
 * Provider-agnostic contract for a single LLM completion call. Every AI-pack
 * node that talks to a language model (the prompt node, the bounded agent
 * node, the AI flow builder) depends on this interface, never a concrete
 * provider SDK directly — swapping providers means swapping the bound
 * implementation, nothing else in this package changes.
 *
 * @api
 */
interface LlmClient
{
    public function complete(LlmRequest $request): LlmResponse;
}
