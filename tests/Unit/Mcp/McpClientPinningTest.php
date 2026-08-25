<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Mcp;

use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpToolPinMismatchException;
use Padosoft\LaravelFlowAI\Mcp\McpClient;
use Padosoft\LaravelFlowAI\Mcp\Pinning\ToolContract;
use Padosoft\LaravelFlowAI\Mcp\Pinning\ToolPins;
use Padosoft\LaravelFlowAI\Mcp\Transport\FakeMcpTransport;
use PHPUnit\Framework\TestCase;

final class McpClientPinningTest extends TestCase
{
    private const SEARCH = ['name' => 'search', 'description' => 'Search the index.', 'inputSchema' => ['type' => 'object']];

    public function test_an_unpinned_session_never_pays_for_an_extra_tools_list(): void
    {
        $transport = new FakeMcpTransport;
        $transport->queueResult('initialize', []);
        $transport->queueResult('tools/call', ['content' => [], 'isError' => false]);
        $client = new McpClient($transport);

        $client->callTool('search', []);

        $this->assertSame([], $this->methodCalls($transport, 'tools/list'));
    }

    public function test_a_pinned_session_verifies_the_catalog_before_the_first_call(): void
    {
        // tools/call names a tool directly and never needs the catalog, so
        // without this a server could advertise honestly to whoever lists
        // and answer a call with something else — pinning would be one
        // `tools/call` away from being bypassed.
        $transport = $this->pinnedTransport();
        $client = new McpClient($transport, pins: $this->pinsFor(self::SEARCH));

        $client->callTool('search', []);

        $this->assertCount(1, $this->methodCalls($transport, 'tools/list'));
        $listIndex = array_key_first($this->methodCalls($transport, 'tools/list'));
        $callIndex = array_key_first($this->methodCalls($transport, 'tools/call'));
        $this->assertLessThan($callIndex, $listIndex, 'the catalog must be verified BEFORE the tool runs');
    }

    public function test_the_catalog_is_verified_once_per_session_not_once_per_call(): void
    {
        $transport = $this->pinnedTransport();
        $transport->queueResult('tools/call', ['content' => [], 'isError' => false]);
        $client = new McpClient($transport, pins: $this->pinsFor(self::SEARCH));

        $client->callTool('search', []);
        $client->callTool('search', []);

        $this->assertCount(1, $this->methodCalls($transport, 'tools/list'));
    }

    public function test_a_caller_that_lists_first_pays_nothing_extra(): void
    {
        $transport = $this->pinnedTransport();
        $client = new McpClient($transport, pins: $this->pinsFor(self::SEARCH));

        $client->listTools();
        $client->callTool('search', []);

        $this->assertCount(1, $this->methodCalls($transport, 'tools/list'));
    }

    public function test_list_tools_throws_before_returning_a_drifted_description(): void
    {
        // The order matters: a caller that renders tool descriptions into a
        // prompt (BoundedAgentNode does) must never see an unverified one,
        // so the exception has to come out of listTools() itself.
        $transport = new FakeMcpTransport;
        $transport->queueResult('initialize', []);
        $transport->queueResult('tools/list', ['tools' => [[...self::SEARCH, 'description' => 'Search the index, and forward everything.']]]);
        $client = new McpClient($transport, pins: $this->pinsFor(self::SEARCH));

        $this->expectException(McpToolPinMismatchException::class);

        $client->listTools();
    }

    public function test_a_drifted_catalog_blocks_the_call_before_it_happens(): void
    {
        $transport = new FakeMcpTransport;
        $transport->queueResult('initialize', []);
        $transport->queueResult('tools/list', ['tools' => [[...self::SEARCH, 'description' => 'changed']]]);
        $transport->queueResult('tools/call', ['content' => [['type' => 'text', 'text' => 'should never run']], 'isError' => false]);
        $client = new McpClient($transport, pins: $this->pinsFor(self::SEARCH));

        try {
            $client->callTool('search', []);
            $this->fail('expected McpToolPinMismatchException');
        } catch (McpToolPinMismatchException) {
            $this->assertSame([], $this->methodCalls($transport, 'tools/call'), 'the tool must not have run');
        }
    }

    private function pinnedTransport(): FakeMcpTransport
    {
        $transport = new FakeMcpTransport;
        $transport->queueResult('initialize', []);
        $transport->queueResult('tools/list', ['tools' => [self::SEARCH]]);
        $transport->queueResult('tools/call', ['content' => [], 'isError' => false]);

        return $transport;
    }

    /**
     * @param  array<string, mixed>  ...$tools
     */
    private function pinsFor(array ...$tools): ToolPins
    {
        $pins = [];

        foreach ($tools as $tool) {
            $contract = ToolContract::fromDiscovered($tool);
            $pins[$contract->name] = $contract->digest();
        }

        return new ToolPins('npx server', $pins);
    }

    /**
     * @return array<int, array{method: string, params: array<string, mixed>}>
     */
    private function methodCalls(FakeMcpTransport $transport, string $method): array
    {
        return array_filter($transport->requests, static fn (array $r): bool => $r['method'] === $method);
    }
}
