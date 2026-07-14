<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Llm;

use Padosoft\LaravelFlowAI\Llm\AnthropicDriver;
use Padosoft\LaravelFlowAI\Llm\LlmRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
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

    public function test_an_empty_structured_output_object_re_encodes_as_an_object_not_an_array(): void
    {
        // {} and [] both decode to the same empty PHP array under
        // json_decode(..., true) — re-encoding a naively-collapsed empty
        // array would wrongly emit "[]" for a genuinely valid empty JSON
        // OBJECT response (a caller's schema may legitimately allow zero
        // required properties), which LlmPromptNode's structured-output
        // retry loop would then reject as "not an object".
        $schema = ['type' => 'object', 'properties' => new \stdClass];

        $driver = new AnthropicDriver(
            apiKey: 'test-key',
            transport: function (string $url, array $headers, string $body, int $timeout): array {
                return [
                    'status_code' => 200,
                    'body' => json_encode([
                        'model' => 'claude-x',
                        'content' => [
                            ['type' => 'tool_use', 'name' => 'structured_output', 'input' => new \stdClass],
                        ],
                        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                    ], JSON_THROW_ON_ERROR),
                    'error' => '',
                ];
            },
        );

        $response = $driver->complete(new LlmRequest(prompt: 'q', model: 'claude-x', responseSchema: $schema));

        self::assertSame('{}', $response->content);
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

    #[DataProvider('statusLineProvider')]
    public function test_status_code_parsing_handles_reason_phrase_and_http2_no_reason_phrase(string $statusLine, int $expected): void
    {
        // No transport call is ever made here — only the private
        // status-line parser is exercised via reflection — but an explicit
        // always-throwing transport is still passed so this construction
        // stays caught by (and provably compliant with) this test suite's
        // own NoNetworkCallsInTestSuiteTest sweep, rather than needing a
        // special-cased exception to that structural rule.
        $driver = new AnthropicDriver(
            apiKey: 'test-key',
            transport: static function (string $url, array $headers, string $body, int $timeout): array {
                throw new RuntimeException('transport must never be called by this test');
            },
        );
        $method = new ReflectionMethod($driver, 'statusCodeFromHeaders');

        $this->assertSame($expected, $method->invoke($driver, [$statusLine]));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function statusLineProvider(): array
    {
        return [
            'HTTP/1.1 with reason phrase' => ['HTTP/1.1 200 OK', 200],
            // HTTP/2 responses have NO reason phrase at all (per the HTTP/2
            // spec, the status line has no textual reason) — PHP's stream
            // wrapper synthesizes a bare "HTTP/2 200" line for these, which
            // an earlier version of this parser's regex failed to match
            // (required unconditional trailing whitespace after the code),
            // silently treating a genuinely successful response as status 0.
            'HTTP/2 without reason phrase' => ['HTTP/2 200', 200],
            'HTTP/1.1 error with reason phrase' => ['HTTP/1.1 404 Not Found', 404],
            'not a status line' => ['X-Some-Header: value', 0],
        ];
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
