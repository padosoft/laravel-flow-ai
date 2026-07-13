<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp\Transport;

use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpConnectionException;

/**
 * In-process, no-subprocess, no-network scripted transport for the entire
 * test suite — the {@see McpTransport} equivalent of `Llm\FakeDriver`.
 * Script responses per method with {@see queueResult()} (consumed in FIFO
 * order per method) or {@see queueConnectionFailure()}; every call is
 * recorded in {@see $requests}/{@see $notifications} for assertions.
 *
 * @internal
 */
final class FakeMcpTransport implements McpTransport
{
    /** @var array<string, list<array{result: array<string, mixed>}|array{exception: McpConnectionException}>> */
    private array $scripted = [];

    /** @var list<array{method: string, params: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array{method: string, params: array<string, mixed>}> */
    public array $notifications = [];

    public bool $closed = false;

    /**
     * @param  array<string, mixed>  $result
     */
    public function queueResult(string $method, array $result): void
    {
        $this->scripted[$method][] = ['result' => $result];
    }

    public function queueConnectionFailure(string $method, McpConnectionException $exception): void
    {
        $this->scripted[$method][] = ['exception' => $exception];
    }

    public function request(string $method, array $params = []): array
    {
        $this->requests[] = ['method' => $method, 'params' => $params];

        $queue = $this->scripted[$method] ?? [];
        $next = array_shift($queue);
        $this->scripted[$method] = $queue;

        if ($next === null) {
            throw new McpConnectionException("FakeMcpTransport: no scripted response for method [{$method}].");
        }

        if (isset($next['exception'])) {
            throw $next['exception'];
        }

        return $next['result'];
    }

    public function notify(string $method, array $params = []): void
    {
        $this->notifications[] = ['method' => $method, 'params' => $params];
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
