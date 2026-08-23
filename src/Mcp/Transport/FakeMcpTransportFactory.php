<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp\Transport;

/**
 * Test double for {@see McpTransportFactory}: `stdio()` always returns the
 * SAME {@see FakeMcpTransport} instance regardless of the requested
 * command/args, and records every requested `(command, args)` pair for
 * assertions — mirroring `Llm\FakeDriver`'s role for `LlmClient`.
 *
 * @internal
 */
final class FakeMcpTransportFactory implements McpTransportFactory
{
    /** @var list<array{command: string, args: list<string>, env: array<string, string>}> */
    public array $requestedTransports = [];

    public function __construct(
        private readonly FakeMcpTransport $transport = new FakeMcpTransport,
    ) {}

    public function transport(): FakeMcpTransport
    {
        return $this->transport;
    }

    public function stdio(string $command, array $args, array $env = []): McpTransport
    {
        $this->requestedTransports[] = ['command' => $command, 'args' => $args, 'env' => $env];

        return $this->transport;
    }
}
