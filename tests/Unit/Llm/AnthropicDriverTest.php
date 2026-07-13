<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Llm;

use Padosoft\LaravelFlowAI\Llm\AnthropicDriver;
use Padosoft\LaravelFlowAI\Llm\LlmRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AnthropicDriverTest extends TestCase
{
    public function test_request_shape_sent_to_the_provider(): void
    {
        $captured = null;

        $driver = new AnthropicDriver(
            apiKey: 'test-key',
            transport: function (string $url, array $headers, string $body, int $timeout) use (&$captured): array {
                $captured = compact('url', 'headers', 'body', 'timeout');

                return ['status_code' => 200, 'body' => self::anthropicResponseBody('ok'), 'error' => ''];
            },
        );

        $driver->complete(new LlmRequest(
            prompt: 'Say hi',
            model: 'claude-x',
            systemPrompt: 'Be terse.',
            temperature: 0.2,
            maxTokens: 128,
        ));

        self::assertIsArray($captured);
        self::assertSame('https://api.anthropic.com/v1/messages', $captured['url']);
        self::assertContains('x-api-key: test-key', $captured['headers']);
        self::assertContains('anthropic-version: 2023-06-01', $captured['headers']);
        self::assertContains('Content-Type: application/json', $captured['headers']);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($captured['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('claude-x', $decoded['model']);
        self::assertSame(128, $decoded['max_tokens']);
        self::assertSame(0.2, $decoded['temperature']);
        self::assertSame('Be terse.', $decoded['system']);
        self::assertSame([['role' => 'user', 'content' => 'Say hi']], $decoded['messages']);
    }

    public function test_system_prompt_omitted_when_not_provided(): void
    {
        $captured = null;

        $driver = new AnthropicDriver(
            apiKey: 'test-key',
            transport: function (string $url, array $headers, string $body, int $timeout) use (&$captured): array {
                $captured = $body;

                return ['status_code' => 200, 'body' => self::anthropicResponseBody('ok'), 'error' => ''];
            },
        );

        $driver->complete(new LlmRequest(prompt: 'Say hi', model: 'claude-x'));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $captured, true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('system', $decoded);
    }

    public function test_response_is_parsed_into_content_and_usage(): void
    {
        $driver = new AnthropicDriver(
            apiKey: 'test-key',
            transport: fn (string $url, array $headers, string $body, int $timeout): array => [
                'status_code' => 200,
                'body' => self::anthropicResponseBody('Hello there', promptTokens: 12, completionTokens: 34, stopReason: 'end_turn'),
                'error' => '',
            ],
        );

        $response = $driver->complete(new LlmRequest(prompt: 'Say hi', model: 'claude-x'));

        self::assertSame('Hello there', $response->content);
        self::assertSame('claude-x-response', $response->model);
        self::assertSame(12, $response->promptTokens);
        self::assertSame(34, $response->completionTokens);
        self::assertSame(46, $response->totalTokens());
        self::assertSame('end_turn', $response->stopReason);
    }

    public function test_multiple_text_blocks_are_concatenated(): void
    {
        $driver = new AnthropicDriver(
            apiKey: 'test-key',
            transport: fn (string $url, array $headers, string $body, int $timeout): array => [
                'status_code' => 200,
                'body' => json_encode([
                    'model' => 'claude-x',
                    'content' => [
                        ['type' => 'text', 'text' => 'Hello '],
                        ['type' => 'text', 'text' => 'world'],
                    ],
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 2],
                ], JSON_THROW_ON_ERROR),
                'error' => '',
            ],
        );

        $response = $driver->complete(new LlmRequest(prompt: 'Say hi', model: 'claude-x'));

        self::assertSame('Hello world', $response->content);
    }

    public function test_response_schema_is_forwarded_as_a_forced_tool_call(): void
    {
        $captured = null;
        $schema = ['type' => 'object', 'properties' => ['answer' => ['type' => 'string']], 'required' => ['answer']];

        $driver = new AnthropicDriver(
            apiKey: 'test-key',
            transport: function (string $url, array $headers, string $body, int $timeout) use (&$captured): array {
                $captured = $body;

                return [
                    'status_code' => 200,
                    'body' => json_encode([
                        'model' => 'claude-x',
                        'content' => [
                            ['type' => 'tool_use', 'name' => 'structured_output', 'input' => ['answer' => 'yes']],
                        ],
                        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                    ], JSON_THROW_ON_ERROR),
                    'error' => '',
                ];
            },
        );

        $response = $driver->complete(new LlmRequest(prompt: 'q', model: 'claude-x', responseSchema: $schema));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $captured, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($schema, $decoded['tools'][0]['input_schema']);
        self::assertSame(['type' => 'tool', 'name' => 'structured_output'], $decoded['tool_choice']);

        // The caller receives raw text ready to json_decode(), same as any
        // other response — it never needs to know a tool-use mechanism
        // produced it.
        self::assertSame(['answer' => 'yes'], json_decode($response->content, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_no_tools_payload_when_no_response_schema_is_requested(): void
    {
        $captured = null;

        $driver = new AnthropicDriver(
            apiKey: 'test-key',
            transport: function (string $url, array $headers, string $body, int $timeout) use (&$captured): array {
                $captured = $body;

                return ['status_code' => 200, 'body' => self::anthropicResponseBody('ok'), 'error' => ''];
            },
        );

        $driver->complete(new LlmRequest(prompt: 'q', model: 'claude-x'));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $captured, true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('tools', $decoded);
        self::assertArrayNotHasKey('tool_choice', $decoded);
    }

    public function test_missing_model_field_throws_instead_of_defaulting(): void
    {
        $driver = new AnthropicDriver(
            apiKey: 'test-key',
            transport: fn (string $url, array $headers, string $body, int $timeout): array => [
                'status_code' => 200,
                'body' => json_encode([
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ], JSON_THROW_ON_ERROR),
                'error' => '',
            ],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing a valid "model"/');

        $driver->complete(new LlmRequest(prompt: 'q', model: 'claude-x'));
    }

    public function test_non_2xx_status_throws(): void
    {
        $driver = new AnthropicDriver(
            apiKey: 'bad-key',
            transport: fn (string $url, array $headers, string $body, int $timeout): array => [
                'status_code' => 401,
                'body' => '{"error":{"message":"invalid api key"}}',
                'error' => '',
            ],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/status 401/');

        $driver->complete(new LlmRequest(prompt: 'Say hi', model: 'claude-x'));
    }

    public function test_transport_level_failure_surfaces_the_error(): void
    {
        $driver = new AnthropicDriver(
            apiKey: 'test-key',
            transport: fn (string $url, array $headers, string $body, int $timeout): array => [
                'status_code' => 0,
                'body' => '',
                'error' => 'Connection refused',
            ],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Connection refused/');

        $driver->complete(new LlmRequest(prompt: 'Say hi', model: 'claude-x'));
    }

    private static function anthropicResponseBody(
        string $text,
        int $promptTokens = 1,
        int $completionTokens = 1,
        string $stopReason = 'end_turn',
    ): string {
        return json_encode([
            'model' => 'claude-x-response',
            'content' => [['type' => 'text', 'text' => $text]],
            'usage' => ['input_tokens' => $promptTokens, 'output_tokens' => $completionTokens],
            'stop_reason' => $stopReason,
        ], JSON_THROW_ON_ERROR);
    }
}
