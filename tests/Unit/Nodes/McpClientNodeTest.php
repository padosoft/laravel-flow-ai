<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Nodes;

use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Persistence\KeyBasedPayloadRedactor;
use Padosoft\LaravelFlowAI\Guardrails\PolicyEngine;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpConnectionException;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpToolExecutionException;
use Padosoft\LaravelFlowAI\Mcp\Transport\FakeMcpTransportFactory;
use Padosoft\LaravelFlowAI\Nodes\McpClientNode;
use PHPUnit\Framework\TestCase;

final class McpClientNodeTest extends TestCase
{
    private function context(array $inputs, bool $dryRun = false): NodeContext
    {
        return new NodeContext('run-1', 'definition', 'node-1', $inputs, $dryRun);
    }

    public function test_fake_mcp_server_round_trip_maps_ports_correctly(): void
    {
        $factory = new FakeMcpTransportFactory;
        $factory->transport()->queueResult('initialize', []);
        $factory->transport()->queueResult('tools/call', ['content' => [['type' => 'text', 'text' => '8']], 'isError' => false]);
        $node = new McpClientNode($factory);

        $result = $node->execute($this->context([
            'command' => 'npx',
            'args' => ['-y', 'calculator-mcp-server'],
            'tool' => 'add',
            'arguments' => ['a' => 5, 'b' => 3],
        ]));

        $this->assertTrue($result->success);
        $this->assertSame([['type' => 'text', 'text' => '8']], $result->outputs['result']);
        $this->assertSame(['command' => 'npx', 'args' => ['-y', 'calculator-mcp-server']], $factory->requestedTransports[0]);

        $callToolRequest = $factory->transport()->requests[array_key_last($factory->transport()->requests)];
        $this->assertSame(['name' => 'add', 'arguments' => ['a' => 5, 'b' => 3]], $callToolRequest['params']);
    }

    public function test_tool_error_surfaces_as_typed_node_failure(): void
    {
        $factory = new FakeMcpTransportFactory;
        $factory->transport()->queueResult('initialize', []);
        $factory->transport()->queueResult('tools/call', ['content' => [['type' => 'text', 'text' => 'bad input']], 'isError' => true]);
        $node = new McpClientNode($factory);

        $result = $node->execute($this->context([
            'command' => 'npx',
            'tool' => 'divide',
            'arguments' => ['a' => 1, 'b' => 0],
        ]));

        $this->assertFalse($result->success);
        $this->assertInstanceOf(McpToolExecutionException::class, $result->error);
    }

    public function test_connection_failure_surfaces_as_a_different_typed_node_failure(): void
    {
        // Distinguishable from McpToolExecutionException above: the call
        // never completed at all, a categorically different failure class.
        $factory = new FakeMcpTransportFactory;
        $factory->transport()->queueConnectionFailure('initialize', new McpConnectionException('server unreachable'));
        $node = new McpClientNode($factory);

        $result = $node->execute($this->context(['command' => 'npx', 'tool' => 'add']));

        $this->assertFalse($result->success);
        $this->assertInstanceOf(McpConnectionException::class, $result->error);
        $this->assertNotInstanceOf(McpToolExecutionException::class, $result->error);
    }

    public function test_the_transport_is_always_closed_even_on_failure(): void
    {
        $factory = new FakeMcpTransportFactory;
        $factory->transport()->queueConnectionFailure('initialize', new McpConnectionException('boom'));
        $node = new McpClientNode($factory);

        $node->execute($this->context(['command' => 'npx', 'tool' => 'add']));

        $this->assertTrue($factory->transport()->closed);
    }

    public function test_a_policy_denial_blocks_before_any_transport_is_built(): void
    {
        // Guardrails reuse: the egress allowlist gates a synthetic
        // "stdio:{command}" pseudo-host, since spawning an arbitrary local
        // command is a real security boundary, not just a network call.
        $factory = new FakeMcpTransportFactory;
        $policy = new PolicyEngine(egressAllowlist: ['stdio:trusted-server']);
        $node = new McpClientNode($factory, policy: $policy);

        $result = $node->execute($this->context(['command' => 'untrusted-server', 'tool' => 'add']));

        $this->assertFalse($result->success);
        $this->assertSame([], $factory->requestedTransports, 'no transport was ever built for a denied call');
    }

    public function test_a_policy_allow_permits_the_call(): void
    {
        $factory = new FakeMcpTransportFactory;
        $factory->transport()->queueResult('initialize', []);
        $factory->transport()->queueResult('tools/call', ['content' => [], 'isError' => false]);
        $policy = new PolicyEngine(egressAllowlist: ['stdio:trusted-server']);
        $node = new McpClientNode($factory, policy: $policy);

        $result = $node->execute($this->context(['command' => 'trusted-server', 'tool' => 'add']));

        $this->assertTrue($result->success);
    }

    public function test_arguments_are_redacted_before_the_tool_call(): void
    {
        $factory = new FakeMcpTransportFactory;
        $factory->transport()->queueResult('initialize', []);
        $factory->transport()->queueResult('tools/call', ['content' => [], 'isError' => false]);
        $redactor = new KeyBasedPayloadRedactor(enabled: true, keys: ['api_key'], replacement: '[redacted]');
        $node = new McpClientNode($factory, redactor: $redactor);

        $node->execute($this->context([
            'command' => 'npx',
            'tool' => 'call-api',
            'arguments' => ['api_key' => 'sk-super-sensitive', 'query' => 'flows'],
        ]));

        $callToolRequest = $factory->transport()->requests[array_key_last($factory->transport()->requests)];
        $this->assertSame('[redacted]', $callToolRequest['params']['arguments']['api_key']);
        $this->assertSame('flows', $callToolRequest['params']['arguments']['query']);
    }

    public function test_dry_run_never_builds_a_transport(): void
    {
        $factory = new FakeMcpTransportFactory;
        $node = new McpClientNode($factory);

        $result = $node->execute($this->context(['command' => 'npx', 'tool' => 'add'], dryRun: true));

        $this->assertTrue($result->dryRunSkipped);
        $this->assertSame([], $factory->requestedTransports);
    }
}
