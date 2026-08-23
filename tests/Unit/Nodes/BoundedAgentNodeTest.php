<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Nodes;

use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Persistence\KeyBasedPayloadRedactor;
use Padosoft\LaravelFlowAI\Contracts\DelegatedIdentityResolver;
use Padosoft\LaravelFlowAI\Guardrails\PolicyEngine;
use Padosoft\LaravelFlowAI\Identity\DelegatedIdentity;
use Padosoft\LaravelFlowAI\Identity\Exceptions\GrantRevokedException;
use Padosoft\LaravelFlowAI\Llm\FakeDriver;
use Padosoft\LaravelFlowAI\Llm\LlmResponse;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpConnectionException;
use Padosoft\LaravelFlowAI\Mcp\Transport\FakeMcpTransportFactory;
use Padosoft\LaravelFlowAI\Nodes\BoundedAgentNode;
use Padosoft\LaravelFlowAI\Nodes\Exceptions\AgentBudgetExhaustedException;
use Padosoft\LaravelFlowAI\Nodes\Exceptions\AgentToolNotAllowedException;
use PHPUnit\Framework\TestCase;

final class BoundedAgentNodeTest extends TestCase
{
    private function context(array $inputs, bool $dryRun = false): NodeContext
    {
        return new NodeContext('run-1', 'definition', 'node-1', $inputs, $dryRun);
    }

    private function baseInputs(array $overrides = []): array
    {
        return [...['task' => 'Do the thing.', 'model' => 'claude-x', 'command' => 'npx'], ...$overrides];
    }

    public function test_a_final_answer_on_the_first_iteration_succeeds(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"final_answer","answer":"42"}', model: 'claude-x', promptTokens: 10, completionTokens: 5),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $node = new BoundedAgentNode($driver, $mcp);

        $result = $node->execute($this->context($this->baseInputs()));

        $this->assertTrue($result->success);
        $this->assertSame('final_answer', $result->outputs['result']['outcome']);
        $this->assertSame('42', $result->outputs['result']['answer']);
        $this->assertSame([], $result->outputs['result']['transcript']);
        $this->assertSame(15, $result->businessImpact['tokens']['total']);
    }

    public function test_business_impact_reports_the_providers_actual_model_not_the_requested_one(): void
    {
        // A provider may canonicalize/alias/route the requested model id —
        // reporting the REQUESTED name would misattribute token spend.
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"final_answer","answer":"ok"}', model: 'claude-x-canonical-2026-07', promptTokens: 10, completionTokens: 5),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $node = new BoundedAgentNode($driver, $mcp);

        $result = $node->execute($this->context($this->baseInputs(['model' => 'claude-x'])));

        $this->assertSame('claude-x-canonical-2026-07', $result->businessImpact['model']);
    }

    public function test_a_pending_approval_halt_also_reports_the_providers_actual_model(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"call_tool","tool":"risky-flow","arguments":{}}', model: 'claude-x-canonical', promptTokens: 5, completionTokens: 5),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $mcp->transport()->queueResult('tools/list', ['tools' => []]);
        $pendingJson = json_encode(['status' => 'pending_approval', 'run_id' => 'run-1', 'message' => 'x'], JSON_THROW_ON_ERROR);
        $mcp->transport()->queueResult('tools/call', ['content' => [['type' => 'text', 'text' => $pendingJson]], 'isError' => false]);
        $node = new BoundedAgentNode($driver, $mcp, allowedTools: ['risky-flow']);

        $result = $node->execute($this->context($this->baseInputs(['model' => 'claude-x'])));

        $this->assertSame('claude-x-canonical', $result->businessImpact['model']);
    }

    public function test_budget_exhaustion_halts_with_distinct_state(): void
    {
        // Iteration 1's response alone already exceeds the 100-token budget,
        // but it requests a TOOL CALL, not a final answer — the budget is
        // only checked BEFORE the next LLM call, so exhaustion surfaces on
        // iteration 2's pre-check, never mid-iteration-1.
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"call_tool","tool":"echo","arguments":{}}', model: 'claude-x', promptTokens: 80, completionTokens: 80),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $mcp->transport()->queueResult('tools/list', ['tools' => []]);
        $mcp->transport()->queueResult('tools/call', ['content' => [['type' => 'text', 'text' => 'ok']], 'isError' => false]);
        $node = new BoundedAgentNode($driver, $mcp, allowedTools: ['echo'], maxIterations: 5, maxTotalTokens: 100);

        $result = $node->execute($this->context($this->baseInputs()));

        $this->assertFalse($result->success);
        $this->assertInstanceOf(AgentBudgetExhaustedException::class, $result->error);
        $this->assertSame('tokens', $result->error->budget);
        $this->assertSame(1, $driver->requestCount(), 'the budget-exhausted 2nd call is never made');
    }

    public function test_iteration_budget_exhausted_without_a_final_answer(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"call_tool","tool":"echo","arguments":{}}', model: 'claude-x', promptTokens: 1, completionTokens: 1),
            new LlmResponse(content: '{"action":"call_tool","tool":"echo","arguments":{}}', model: 'claude-x', promptTokens: 1, completionTokens: 1),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $mcp->transport()->queueResult('tools/list', ['tools' => []]);
        $mcp->transport()->queueResult('tools/call', ['content' => [], 'isError' => false]);
        $mcp->transport()->queueResult('tools/call', ['content' => [], 'isError' => false]);
        $node = new BoundedAgentNode($driver, $mcp, allowedTools: ['echo'], maxIterations: 2, maxTotalTokens: 1000);

        $result = $node->execute($this->context($this->baseInputs()));

        $this->assertFalse($result->success);
        $this->assertInstanceOf(AgentBudgetExhaustedException::class, $result->error);
        $this->assertSame('iterations', $result->error->budget);
        $this->assertSame(2, $driver->requestCount());
    }

    public function test_allowlist_violation_blocked(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"call_tool","tool":"forbidden","arguments":{}}', model: 'claude-x', promptTokens: 5, completionTokens: 5),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $mcp->transport()->queueResult('tools/list', ['tools' => []]);
        // Deliberately NO 'tools/call' scripted response: the assertion this
        // test exists for is that the call never happens at all.
        $node = new BoundedAgentNode($driver, $mcp, allowedTools: ['echo']);

        $result = $node->execute($this->context($this->baseInputs()));

        $this->assertFalse($result->success);
        $this->assertInstanceOf(AgentToolNotAllowedException::class, $result->error);
        $this->assertSame('forbidden', $result->error->tool);
        $toolCalls = array_filter($mcp->transport()->requests, static fn (array $r): bool => $r['method'] === 'tools/call');
        $this->assertSame([], $toolCalls, 'the disallowed tool was never actually called');
    }

    public function test_a_missing_tool_name_self_repairs_instead_of_halting_as_an_allowlist_violation(): void
    {
        // An empty tool name is a MALFORMED decision, not a security
        // violation — it must never masquerade as an allowlist denial (an
        // empty string is never actually configured in $allowedTools).
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"call_tool","arguments":{}}', model: 'claude-x', promptTokens: 5, completionTokens: 5),
            new LlmResponse(content: '{"action":"final_answer","answer":"recovered"}', model: 'claude-x', promptTokens: 5, completionTokens: 5),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $mcp->transport()->queueResult('tools/list', ['tools' => []]);
        $node = new BoundedAgentNode($driver, $mcp, allowedTools: ['echo'], maxIterations: 5);

        $result = $node->execute($this->context($this->baseInputs()));

        $this->assertTrue($result->success);
        $this->assertSame('recovered', $result->outputs['result']['answer']);
        $this->assertSame('invalid_decision', $result->outputs['result']['transcript'][0]['type']);
        $this->assertStringContainsString('missing a "tool" name', $driver->requests()[1]->prompt);
    }

    public function test_loop_transcript_persisted_redacted(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"call_tool","tool":"lookup","arguments":{"api_key":"sk-super-sensitive","query":"flows"}}', model: 'claude-x', promptTokens: 10, completionTokens: 10),
            new LlmResponse(content: '{"action":"final_answer","answer":"done"}', model: 'claude-x', promptTokens: 10, completionTokens: 10),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $mcp->transport()->queueResult('tools/list', ['tools' => []]);
        $mcp->transport()->queueResult('tools/call', ['content' => [['type' => 'text', 'text' => 'result text']], 'isError' => false]);
        $redactor = new KeyBasedPayloadRedactor(enabled: true, keys: ['api_key'], replacement: '[redacted]');
        $node = new BoundedAgentNode($driver, $mcp, allowedTools: ['lookup'], redactor: $redactor);

        $result = $node->execute($this->context($this->baseInputs()));

        $this->assertTrue($result->success);
        $transcript = $result->outputs['result']['transcript'];
        $this->assertSame('lookup', $transcript[0]['tool']);
        $this->assertSame('[redacted]', $transcript[0]['arguments']['api_key']);
        $this->assertSame('flows', $transcript[0]['arguments']['query']);
        $this->assertStringNotContainsString('sk-super-sensitive', json_encode($result->outputs, JSON_THROW_ON_ERROR));
    }

    public function test_a_sensitive_tool_result_never_reaches_the_outbound_prompt(): void
    {
        // Redacting only the FINAL stored transcript (the test above) is not
        // enough: every transcript entry is also embedded into the NEXT
        // iteration's outbound prompt sent to the EXTERNAL LLM provider —
        // that must never carry the raw secret either, regardless of what
        // happens to it afterward.
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"call_tool","tool":"lookup","arguments":{}}', model: 'claude-x', promptTokens: 10, completionTokens: 10),
            new LlmResponse(content: '{"action":"final_answer","answer":"done"}', model: 'claude-x', promptTokens: 10, completionTokens: 10),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $mcp->transport()->queueResult('tools/list', ['tools' => []]);
        $mcp->transport()->queueResult('tools/call', ['content' => [['type' => 'text', 'text' => 'ssn: 123-45-6789']], 'isError' => false]);
        $redactor = new KeyBasedPayloadRedactor(enabled: true, keys: ['text'], replacement: '[redacted]');
        $node = new BoundedAgentNode($driver, $mcp, allowedTools: ['lookup'], redactor: $redactor);

        $node->execute($this->context($this->baseInputs()));

        $this->assertCount(2, $driver->requests());
        $this->assertStringNotContainsString('123-45-6789', $driver->requests()[1]->prompt, 'the 2nd prompt embeds the transcript from the 1st tool call');
    }

    public function test_a_tool_execution_error_is_fed_back_and_the_loop_continues(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"call_tool","tool":"flaky","arguments":{}}', model: 'claude-x', promptTokens: 5, completionTokens: 5),
            new LlmResponse(content: '{"action":"final_answer","answer":"recovered"}', model: 'claude-x', promptTokens: 5, completionTokens: 5),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $mcp->transport()->queueResult('tools/list', ['tools' => []]);
        $mcp->transport()->queueResult('tools/call', ['content' => [['type' => 'text', 'text' => 'bad input']], 'isError' => true]);
        $node = new BoundedAgentNode($driver, $mcp, allowedTools: ['flaky']);

        $result = $node->execute($this->context($this->baseInputs()));

        $this->assertTrue($result->success);
        $this->assertSame('recovered', $result->outputs['result']['answer']);
        $this->assertSame('tool_error', $result->outputs['result']['transcript'][0]['type']);
    }

    public function test_a_connection_failure_halts_immediately_not_retried(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"call_tool","tool":"echo","arguments":{}}', model: 'claude-x', promptTokens: 5, completionTokens: 5),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $mcp->transport()->queueResult('tools/list', ['tools' => []]);
        $mcp->transport()->queueConnectionFailure('tools/call', new McpConnectionException('server unreachable'));
        $node = new BoundedAgentNode($driver, $mcp, allowedTools: ['echo'], maxIterations: 5);

        $result = $node->execute($this->context($this->baseInputs()));

        $this->assertFalse($result->success);
        $this->assertInstanceOf(McpConnectionException::class, $result->error);
        $this->assertSame(1, $driver->requestCount(), 'a connection failure is never retried within the loop');
    }

    public function test_a_tools_list_connection_failure_surfaces_as_a_typed_node_failure(): void
    {
        $driver = new FakeDriver([]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $mcp->transport()->queueConnectionFailure('tools/list', new McpConnectionException('server unreachable'));
        $node = new BoundedAgentNode($driver, $mcp, allowedTools: ['echo']);

        $result = $node->execute($this->context($this->baseInputs()));

        $this->assertFalse($result->success);
        $this->assertInstanceOf(McpConnectionException::class, $result->error);
        $this->assertSame(0, $driver->requestCount(), 'no LLM call is ever made when tool discovery fails');
    }

    public function test_a_non_encodable_tool_result_surfaces_as_a_typed_node_failure(): void
    {
        // Invalid UTF-8 in a tool result poisons the transcript json_encode()
        // on the NEXT iteration's prompt render — the loop must not throw
        // uncaught, it must map to a structured NodeResult::failed() like
        // LlmPromptNode does for its own template-render encoding failures.
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"call_tool","tool":"echo","arguments":{}}', model: 'claude-x', promptTokens: 5, completionTokens: 5),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $mcp->transport()->queueResult('tools/list', ['tools' => []]);
        $mcp->transport()->queueResult('tools/call', ['content' => [['type' => 'text', 'text' => "\xB1\x31invalid-utf8"]], 'isError' => false]);
        $node = new BoundedAgentNode($driver, $mcp, allowedTools: ['echo'], maxIterations: 5);

        $result = $node->execute($this->context($this->baseInputs()));

        $this->assertFalse($result->success);
        $this->assertInstanceOf(\JsonException::class, $result->error);
    }

    public function test_an_invalid_decision_retries_then_succeeds(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: 'not json at all', model: 'claude-x', promptTokens: 5, completionTokens: 2),
            new LlmResponse(content: '{"action":"final_answer","answer":"fixed"}', model: 'claude-x', promptTokens: 5, completionTokens: 2),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $node = new BoundedAgentNode($driver, $mcp, maxIterations: 5);

        $result = $node->execute($this->context($this->baseInputs()));

        $this->assertTrue($result->success);
        $this->assertSame('fixed', $result->outputs['result']['answer']);
        $this->assertStringContainsString('invalid', $driver->requests()[1]->prompt);
        $this->assertStringContainsString('not valid JSON', $driver->requests()[1]->prompt);
    }

    public function test_a_pending_approval_tool_result_halts_the_loop(): void
    {
        // Mirrors FlowToolServer::pendingApprovalResult()'s envelope shape —
        // the flagship "approval escape hatch" scenario: a called tool is
        // itself an approval-gated flow exposed via F-PR5.
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"call_tool","tool":"risky-flow","arguments":{}}', model: 'claude-x', promptTokens: 5, completionTokens: 5),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $mcp->transport()->queueResult('tools/list', ['tools' => []]);
        $pendingJson = json_encode(['status' => 'pending_approval', 'run_id' => 'run-42', 'message' => 'awaiting sign-off'], JSON_THROW_ON_ERROR);
        $mcp->transport()->queueResult('tools/call', ['content' => [['type' => 'text', 'text' => $pendingJson]], 'isError' => false]);
        $node = new BoundedAgentNode($driver, $mcp, allowedTools: ['risky-flow'], maxIterations: 5);

        $result = $node->execute($this->context($this->baseInputs()));

        $this->assertTrue($result->success);
        $this->assertSame('pending_approval', $result->outputs['result']['outcome']);
        $this->assertSame('run-42', $result->outputs['result']['run_id']);
        $this->assertSame(1, $driver->requestCount(), 'the loop halts immediately, it does not keep polling');
    }

    public function test_a_policy_denial_blocks_before_any_llm_call(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"final_answer","answer":"unreachable"}', model: 'claude-x', promptTokens: 5, completionTokens: 5),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $policy = new PolicyEngine(egressAllowlist: ['stdio:trusted-server']);
        $node = new BoundedAgentNode($driver, $mcp, policy: $policy);

        $result = $node->execute($this->context($this->baseInputs(['command' => 'untrusted-server'])));

        $this->assertFalse($result->success);
        $this->assertSame(0, $driver->requestCount());
        $this->assertSame([], $mcp->requestedTransports, 'no transport was ever built for a denied call');
    }

    public function test_dry_run_never_calls_the_llm_or_builds_a_transport(): void
    {
        $driver = new FakeDriver([]);
        $mcp = new FakeMcpTransportFactory;
        $node = new BoundedAgentNode($driver, $mcp);

        $result = $node->execute($this->context($this->baseInputs(), dryRun: true));

        $this->assertTrue($result->dryRunSkipped);
        $this->assertSame(0, $driver->requestCount());
        $this->assertSame([], $mcp->requestedTransports);
    }

    public function test_the_transport_is_always_closed_even_on_failure(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"call_tool","tool":"echo","arguments":{}}', model: 'claude-x', promptTokens: 5, completionTokens: 5),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $mcp->transport()->queueResult('tools/list', ['tools' => []]);
        $mcp->transport()->queueConnectionFailure('tools/call', new McpConnectionException('boom'));
        $node = new BoundedAgentNode($driver, $mcp, allowedTools: ['echo']);

        $node->execute($this->context($this->baseInputs()));

        $this->assertTrue($mcp->transport()->closed);
    }

    public function test_construction_rejects_a_sub_one_max_iterations(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new BoundedAgentNode(new FakeDriver([]), new FakeMcpTransportFactory, maxIterations: 0);
    }

    public function test_construction_rejects_a_sub_one_max_total_tokens(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new BoundedAgentNode(new FakeDriver([]), new FakeMcpTransportFactory, maxTotalTokens: 0);
    }

    public function test_a_zero_max_cost_usd_without_a_rate_is_still_a_noop(): void
    {
        // The sharpest form of the "cost gate needs a rate" regression: a
        // maxCostUsd of exactly 0.0 would make `$costSoFar >= $maxCostUsd`
        // true on iteration 2's very first check (0.0 >= 0.0) if the gate
        // were keyed on maxCostUsd alone — it must ALSO require
        // costPerThousandTokens before enforcing anything.
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"call_tool","tool":"echo","arguments":{}}', model: 'claude-x', promptTokens: 1, completionTokens: 1),
            new LlmResponse(content: '{"action":"final_answer","answer":"done"}', model: 'claude-x', promptTokens: 1, completionTokens: 1),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $mcp->transport()->queueResult('tools/list', ['tools' => []]);
        $mcp->transport()->queueResult('tools/call', ['content' => [], 'isError' => false]);
        $node = new BoundedAgentNode($driver, $mcp, allowedTools: ['echo'], maxIterations: 5, maxTotalTokens: 1_000_000, maxCostUsd: 0.0);

        $result = $node->execute($this->context($this->baseInputs()));

        $this->assertTrue($result->success);
        $this->assertSame(2, $driver->requestCount());
    }

    public function test_cost_budget_is_a_noop_without_a_configured_rate(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"call_tool","tool":"echo","arguments":{}}', model: 'claude-x', promptTokens: 1000, completionTokens: 1000),
            new LlmResponse(content: '{"action":"final_answer","answer":"done"}', model: 'claude-x', promptTokens: 1, completionTokens: 1),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $mcp->transport()->queueResult('tools/list', ['tools' => []]);
        $mcp->transport()->queueResult('tools/call', ['content' => [], 'isError' => false]);
        // maxCostUsd set but costPerThousandTokens is NOT — cost can never be
        // computed, so the cost gate must stay a no-op rather than halting.
        $node = new BoundedAgentNode($driver, $mcp, allowedTools: ['echo'], maxIterations: 5, maxTotalTokens: 1_000_000, maxCostUsd: 0.01);

        $result = $node->execute($this->context($this->baseInputs()));

        $this->assertTrue($result->success);
    }

    public function test_the_task_template_renders_input_variables(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"final_answer","answer":"ok"}', model: 'claude-x', promptTokens: 1, completionTokens: 1),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $node = new BoundedAgentNode($driver, $mcp);

        $node->execute($this->context($this->baseInputs([
            'task' => 'Summarize {{topic}}.',
            'variables' => ['topic' => 'flows'],
        ])));

        $this->assertStringContainsString('Summarize flows.', $driver->requests()[0]->prompt);
    }

    /**
     * @param  list<DelegatedIdentity|GrantRevokedException>  $script  consumed one per resolve() call; the last entry repeats
     */
    private function scriptedResolver(array $script): DelegatedIdentityResolver
    {
        return new class($script) implements DelegatedIdentityResolver
        {
            public function __construct(private array $script) {}

            public function resolve(): ?DelegatedIdentity
            {
                $next = count($this->script) > 1 ? array_shift($this->script) : $this->script[0];

                if ($next instanceof GrantRevokedException) {
                    throw $next;
                }

                return $next;
            }
        };
    }

    private function identity(): DelegatedIdentity
    {
        return new DelegatedIdentity(subject: 'user:42', actor: 'agent:01J', token: 'tok-secret', grantId: 'dgr_1');
    }

    public function test_a_resolved_delegated_identity_reaches_the_mcp_server_as_process_env(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"final_answer","answer":"ok"}', model: 'claude-x', promptTokens: 1, completionTokens: 1),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $node = new BoundedAgentNode($driver, $mcp, identity: $this->scriptedResolver([$this->identity()]));

        $result = $node->execute($this->context($this->baseInputs()));

        $this->assertTrue($result->success);
        $this->assertSame([
            DelegatedIdentity::ENV_TOKEN => 'tok-secret',
            DelegatedIdentity::ENV_SUBJECT => 'user:42',
            DelegatedIdentity::ENV_ACTOR => 'agent:01J',
        ], $mcp->requestedTransports[0]['env']);
    }

    public function test_without_a_resolver_the_mcp_server_env_stays_empty(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"final_answer","answer":"ok"}', model: 'claude-x', promptTokens: 1, completionTokens: 1),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $node = new BoundedAgentNode($driver, $mcp);

        $node->execute($this->context($this->baseInputs()));

        $this->assertSame([], $mcp->requestedTransports[0]['env']);
    }

    public function test_a_grant_already_revoked_at_spawn_halts_before_any_llm_call_or_subprocess(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"final_answer","answer":"never"}', model: 'claude-x', promptTokens: 1, completionTokens: 1),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $node = new BoundedAgentNode($driver, $mcp, identity: $this->scriptedResolver([new GrantRevokedException('dgr_1')]));

        $result = $node->execute($this->context($this->baseInputs()));

        $this->assertFalse($result->success);
        $this->assertInstanceOf(GrantRevokedException::class, $result->error);
        $this->assertSame('dgr_1', $result->error->grantId);
        $this->assertSame(0, $driver->requestCount(), 'the LLM is never consulted on a revoked grant');
        $this->assertSame([], $mcp->requestedTransports, 'no MCP server is ever spawned on a revoked grant');
    }

    public function test_a_revocation_landing_mid_run_halts_before_the_next_tool_call(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"action":"call_tool","tool":"echo","arguments":{}}', model: 'claude-x', promptTokens: 1, completionTokens: 1),
        ]);
        $mcp = new FakeMcpTransportFactory;
        $mcp->transport()->queueResult('initialize', []);
        $mcp->transport()->queueResult('tools/list', ['tools' => []]);
        $mcp->transport()->queueResult('tools/call', ['content' => [['type' => 'text', 'text' => 'never']], 'isError' => false]);
        // Spawn-time resolve succeeds; the pre-tool-call re-check throws.
        $resolver = $this->scriptedResolver([$this->identity(), new GrantRevokedException('dgr_1')]);
        $node = new BoundedAgentNode($driver, $mcp, allowedTools: ['echo'], identity: $resolver);

        $result = $node->execute($this->context($this->baseInputs()));

        $this->assertFalse($result->success);
        $this->assertInstanceOf(GrantRevokedException::class, $result->error);
        $this->assertNotContains(
            'tools/call',
            array_column($mcp->transport()->requests, 'method'),
            'the tool call was blocked before it happened',
        );
    }
}
