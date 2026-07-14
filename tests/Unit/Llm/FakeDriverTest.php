<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Llm;

use Padosoft\LaravelFlowAI\Llm\FakeDriver;
use Padosoft\LaravelFlowAI\Llm\LlmRequest;
use Padosoft\LaravelFlowAI\Llm\LlmResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FakeDriverTest extends TestCase
{
    public function test_returns_canned_responses_in_order(): void
    {
        $first = new LlmResponse(content: 'first', model: 'fake', promptTokens: 1, completionTokens: 1);
        $second = new LlmResponse(content: 'second', model: 'fake', promptTokens: 1, completionTokens: 1);
        $driver = new FakeDriver([$first, $second]);

        self::assertSame($first, $driver->complete(new LlmRequest(prompt: 'a', model: 'fake')));
        self::assertSame($second, $driver->complete(new LlmRequest(prompt: 'b', model: 'fake')));
    }

    public function test_exhausted_queue_throws(): void
    {
        $driver = new FakeDriver([]);

        $this->expectException(RuntimeException::class);

        $driver->complete(new LlmRequest(prompt: 'a', model: 'fake'));
    }

    public function test_records_every_request(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: 'x', model: 'fake', promptTokens: 0, completionTokens: 0),
        ]);

        $request = new LlmRequest(prompt: 'record me', model: 'fake');
        $driver->complete($request);

        self::assertSame(1, $driver->requestCount());
        self::assertSame([$request], $driver->requests());
    }
}
