<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Contract;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpConnectionException;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpException;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpToolExecutionException;
use Padosoft\LaravelFlowAI\Nodes\McpClientNode;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins this package's `@api` MCP surface: the two DISTINGUISHABLE exception
 * types (F-PR4's own gate criterion — a flow author or a future Advisor
 * must be able to tell a connection failure from a tool-reported failure)
 * and the `ai.mcp.tool` node type. Any future breaking change to this shape
 * must update this test in the same commit.
 */
final class McpClientContractTest extends TestCase
{
    public function test_mcp_connection_exception_and_tool_execution_exception_are_distinguishable_and_share_a_base(): void
    {
        $connection = new ReflectionClass(McpConnectionException::class);
        $tool = new ReflectionClass(McpToolExecutionException::class);

        self::assertTrue($connection->isSubclassOf(McpException::class));
        self::assertTrue($tool->isSubclassOf(McpException::class));
        self::assertFalse($connection->isSubclassOf(McpToolExecutionException::class));
        self::assertFalse($tool->isSubclassOf(McpConnectionException::class));
    }

    public function test_mcp_tool_execution_exception_carries_the_tools_raw_content(): void
    {
        $exception = new McpToolExecutionException('tool failed', [['type' => 'text', 'text' => 'detail']]);

        self::assertSame([['type' => 'text', 'text' => 'detail']], $exception->content);
    }

    public function test_mcp_client_node_type_and_ports(): void
    {
        $reflection = new ReflectionClass(McpClientNode::class);
        $attributes = $reflection->getAttributes(FlowNode::class);

        self::assertCount(1, $attributes);
        $flowNode = $attributes[0]->newInstance();
        self::assertSame('ai.mcp.tool', $flowNode->type);

        foreach (['command', 'args', 'tool', 'arguments'] as $property) {
            self::assertTrue($reflection->hasProperty($property), $property);
        }

        self::assertTrue($reflection->hasProperty('result'));
    }
}
