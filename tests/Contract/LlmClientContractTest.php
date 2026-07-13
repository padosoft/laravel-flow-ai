<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Contract;

use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use Padosoft\LaravelFlowAI\Llm\LlmRequest;
use Padosoft\LaravelFlowAI\Llm\LlmResponse;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Pins the provider-agnostic LLM client surface (`@api`, local to this
 * package — unlike `FlowTrigger`, nothing outside `laravel-flow-ai`
 * implements or consumes this contract, so it is not defined in core). Any
 * future breaking change to this shape must update this test in the same
 * commit, per this program's `@api`-stability discipline.
 */
final class LlmClientContractTest extends TestCase
{
    public function test_llm_client_contract_shape(): void
    {
        $reflection = new ReflectionClass(LlmClient::class);
        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('complete'));

        $method = $reflection->getMethod('complete');
        self::assertCount(1, $method->getParameters());
        self::assertSame(LlmRequest::class, self::typeName($method->getParameters()[0]->getType()));
        self::assertSame(LlmResponse::class, self::typeName($method->getReturnType()));
    }

    public function test_llm_request_shape(): void
    {
        $reflection = new ReflectionClass(LlmRequest::class);

        foreach (['prompt', 'model', 'systemPrompt', 'temperature', 'maxTokens', 'responseSchema'] as $property) {
            self::assertTrue($reflection->hasProperty($property), $property);
        }

        $request = new LlmRequest(prompt: 'hello', model: 'claude-x');
        self::assertSame('hello', $request->prompt);
        self::assertSame('claude-x', $request->model);
        self::assertNull($request->systemPrompt);
        self::assertSame(1.0, $request->temperature);
        self::assertSame(1024, $request->maxTokens);
        self::assertNull($request->responseSchema);
    }

    public function test_llm_response_shape(): void
    {
        $reflection = new ReflectionClass(LlmResponse::class);

        foreach (['content', 'model', 'promptTokens', 'completionTokens', 'stopReason'] as $property) {
            self::assertTrue($reflection->hasProperty($property), $property);
        }

        self::assertTrue($reflection->hasMethod('totalTokens'));

        $response = new LlmResponse(content: 'hi', model: 'claude-x', promptTokens: 3, completionTokens: 5);
        self::assertSame(8, $response->totalTokens());
    }

    private static function typeName(?\ReflectionType $type): ?string
    {
        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }
}
