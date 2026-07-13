<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Mcp;

use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpConnectionException;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpToolExecutionException;
use Padosoft\LaravelFlowAI\Mcp\McpClient;
use Padosoft\LaravelFlowAI\Mcp\Transport\FakeMcpTransport;
use PHPUnit\Framework\TestCase;

final class McpClientTest extends TestCase
{
    public function test_list_tools_returns_the_servers_tool_list(): void
    {
        $transport = new FakeMcpTransport;
        $transport->queueResult('initialize', ['protocolVersion' => '2025-06-18']);
        $transport->queueResult('tools/list', ['tools' => [['name' => 'add', 'inputSchema' => ['type' => 'object']]]]);
        $client = new McpClient($transport);

        $tools = $client->listTools();

        $this->assertSame([['name' => 'add', 'inputSchema' => ['type' => 'object']]], $tools);
    }

    public function test_initialize_handshake_runs_exactly_once_across_multiple_calls(): void
    {
        $transport = new FakeMcpTransport;
        $transport->queueResult('initialize', []);
        $transport->queueResult('tools/list', ['tools' => []]);
        $transport->queueResult('tools/list', ['tools' => []]);
        $client = new McpClient($transport);

        $client->listTools();
        $client->listTools();

        $initializeCalls = array_filter($transport->requests, static fn (array $r): bool => $r['method'] === 'initialize');
        $this->assertCount(1, $initializeCalls, 'initialize must run once, lazily, not once per call');
        $this->assertCount(1, $transport->notifications, 'notifications/initialized sent exactly once');
        $this->assertSame('notifications/initialized', $transport->notifications[0]['method']);
    }

    public function test_call_tool_returns_the_result_content_on_success(): void
    {
        $transport = new FakeMcpTransport;
        $transport->queueResult('initialize', []);
        $transport->queueResult('tools/call', ['content' => [['type' => 'text', 'text' => '8']], 'isError' => false]);
        $client = new McpClient($transport);

        $content = $client->callTool('add', ['a' => 5, 'b' => 3]);

        $this->assertSame([['type' => 'text', 'text' => '8']], $content);
        $callToolRequest = $transport->requests[array_key_last($transport->requests)];
        $this->assertSame(['name' => 'add', 'arguments' => ['a' => 5, 'b' => 3]], $callToolRequest['params']);
    }

    public function test_call_tool_with_is_error_true_throws_a_typed_tool_execution_exception(): void
    {
        $transport = new FakeMcpTransport;
        $transport->queueResult('initialize', []);
        $transport->queueResult('tools/call', ['content' => [['type' => 'text', 'text' => 'division by zero']], 'isError' => true]);
        $client = new McpClient($transport);

        try {
            $client->callTool('divide', ['a' => 1, 'b' => 0]);
            $this->fail('expected McpToolExecutionException');
        } catch (McpToolExecutionException $e) {
            $this->assertSame([['type' => 'text', 'text' => 'division by zero']], $e->content);
        }
    }

    public function test_a_transport_connection_failure_propagates_as_mcp_connection_exception(): void
    {
        $transport = new FakeMcpTransport;
        $transport->queueConnectionFailure('initialize', new McpConnectionException('server unreachable'));
        $client = new McpClient($transport);

        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage('server unreachable');

        $client->listTools();
    }

    public function test_close_delegates_to_the_transport(): void
    {
        $transport = new FakeMcpTransport;
        $client = new McpClient($transport);

        $client->close();

        $this->assertTrue($transport->closed);
    }
}
