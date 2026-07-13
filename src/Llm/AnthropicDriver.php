<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Llm;

use JsonException;
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use RuntimeException;

/**
 * {@see LlmClient} implementation calling Anthropic's Messages API
 * (https://docs.anthropic.com/en/api/messages). Uses an injectable
 * transport callable — mirroring `padosoft/laravel-flow`'s
 * `WebhookDeliveryClient` — so tests never touch the network: inject a
 * fake transport closure instead of relying on a global HTTP fake (this
 * program's `Event::fake()`-called-twice gotcha is exactly the class of
 * global-test-double footgun this pattern avoids). The default transport
 * uses PHP's built-in `stream_context_create()` + `file_get_contents()`
 * HTTP wrapper, adding no extra HTTP client dependency to this package.
 *
 * @api
 */
final class AnthropicDriver implements LlmClient
{
    private const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1/messages';

    private const DEFAULT_API_VERSION = '2023-06-01';

    private const DEFAULT_TIMEOUT_SECONDS = 30;

    /** @var callable(string, list<string>, string, int): array{status_code: int, body: string, error: string} */
    private $transport;

    /**
     * @param  null|callable(string $url, list<string> $headers, string $body, int $timeout): array{status_code: int, body: string, error: string}  $transport
     */
    public function __construct(
        private readonly string $apiKey,
        ?callable $transport = null,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly string $apiVersion = self::DEFAULT_API_VERSION,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
        /** @var callable(string $url, list<string> $headers, string $body, int $timeout): array{status_code: int, body: string, error: string} $resolvedTransport */
        $resolvedTransport = $transport ?? $this->defaultTransport(...);
        $this->transport = $resolvedTransport;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $payload = [
            'model' => $request->model,
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'messages' => [
                ['role' => 'user', 'content' => $request->prompt],
            ],
        ];

        if ($request->systemPrompt !== null) {
            $payload['system'] = $request->systemPrompt;
        }

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new RuntimeException('LLM request payload could not be serialized: '.$e->getMessage(), previous: $e);
        }

        $headers = [
            'Content-Type: application/json',
            'x-api-key: '.$this->apiKey,
            'anthropic-version: '.$this->apiVersion,
        ];

        $result = ($this->transport)($this->baseUrl, $headers, $body, $this->timeoutSeconds);

        if ($result['status_code'] < 200 || $result['status_code'] >= 300) {
            throw new RuntimeException(sprintf(
                'Anthropic API request failed with status %d: %s',
                $result['status_code'],
                $result['error'] !== '' ? $result['error'] : $result['body'],
            ));
        }

        return $this->parseResponse($result['body']);
    }

    private function parseResponse(string $body): LlmResponse
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Anthropic API response could not be decoded: '.$e->getMessage(), previous: $e);
        }

        $contentBlocks = is_array($decoded['content'] ?? null) ? $decoded['content'] : [];
        $text = '';

        foreach ($contentBlocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        /** @var array<string, mixed> $usage */
        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];

        return new LlmResponse(
            content: $text,
            model: is_string($decoded['model'] ?? null) ? $decoded['model'] : '',
            promptTokens: is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : 0,
            completionTokens: is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : 0,
            stopReason: is_string($decoded['stop_reason'] ?? null) ? $decoded['stop_reason'] : null,
        );
    }

    /**
     * @param  list<string>  $headers
     * @return array{status_code: int, body: string, error: string}
     */
    private function defaultTransport(string $url, array $headers, string $body, int $timeout): array
    {
        if ($timeout < 1) {
            throw new RuntimeException('LLM request timeout must be at least 1 second.');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [...$headers, 'Content-Length: '.strlen($body)]),
                'timeout' => $timeout,
                'content' => $body,
                'ignore_errors' => true,
            ],
        ]);

        $errorMessage = null;
        $http_response_header = [];
        // PHP populates $http_response_header in the local scope where file_get_contents() runs.
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $lastError = error_get_last();
            $errorMessage = $lastError['message'] ?? 'Failed to reach the LLM provider.';
            $statusCode = 0;
            $responseBody = '';
        } else {
            $statusCode = $this->statusCodeFromHeaders($http_response_header);
            $responseBody = (string) $response;
        }

        return [
            'status_code' => $statusCode,
            'body' => $responseBody,
            'error' => (string) $errorMessage,
        ];
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function statusCodeFromHeaders(array $headers): int
    {
        if ($headers === []) {
            return 0;
        }

        foreach (array_reverse($headers) as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)\s+/i', $headerLine, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }
}
