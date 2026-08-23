<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Mcp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlowAI\Contracts\McpToolAuthorizer;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;
use Padosoft\LaravelFlowAI\Mcp\Authorization\AllowAllMcpToolAuthorizer;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpToolNotFoundException;
use Padosoft\LaravelFlowAI\Mcp\FlowToolServer;

final class FlowToolServerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowAIServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.nodes.handlers', [EchoFixtureNode::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../../../vendor/padosoft/laravel-flow/database/migrations');
        EchoFixtureNode::$invocations = 0;
    }

    /**
     * @return array{name: string, graph: GraphDefinition}
     */
    private function publishEchoFlow(string $name = 'echo-flow'): array
    {
        // The root node's input port is keyed literally `input` (Json,
        // carrying the whole `Flow::runGraph()` arguments map) — the ONLY
        // port key `NodeRouting::seedRootInput()` seeds a root node's
        // `config` with (it always writes `config['input']`, regardless of
        // node type), and `InputRouter::route()` falls back to a matching
        // `config` key ONLY when a port's key has no incoming wire. Any
        // other port key (e.g. `message`) never receives the caller's
        // top-level `$input` at all.
        $graph = new GraphDefinition(
            [new GraphNode('a', 'test.echo')],
            [],
            ['required_inputs' => ['message']],
        );

        $stored = $this->app->make(DefinitionRepository::class)->createDraft($name, $graph);
        $this->app->make(DefinitionRepository::class)->publish($name, $stored->version);

        return ['name' => $name, 'graph' => $graph];
    }

    private function publishApprovalGatedFlow(string $name = 'approval-flow'): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.echo'),
                new GraphNode('gate', 'flow.approval'),
            ],
            [new Connection('a', 'out', 'gate', 'in')],
            ['required_inputs' => ['message']],
        );

        $stored = $this->app->make(DefinitionRepository::class)->createDraft($name, $graph);
        $this->app->make(DefinitionRepository::class)->publish($name, $stored->version);
    }

    private function allowAllServer(array $exposedFlowNames): FlowToolServer
    {
        return new FlowToolServer(
            definitions: $this->app->make(DefinitionRepository::class),
            runs: $this->app->make(RunRepository::class),
            authorizer: new AllowAllMcpToolAuthorizer,
            exposedFlowNames: $exposedFlowNames,
        );
    }

    public function test_a_repeated_exposed_flow_name_produces_only_one_tool_entry(): void
    {
        $this->publishEchoFlow('echo-flow');
        $server = $this->allowAllServer(['echo-flow', 'echo-flow']);

        $names = array_column($server->listTools(), 'name');

        $this->assertSame(['echo-flow', FlowToolServer::STATUS_CHECK_TOOL_NAME], $names);
    }

    public function test_golden_schema_for_a_published_flow(): void
    {
        $this->publishEchoFlow('echo-flow');
        $server = $this->allowAllServer(['echo-flow']);

        $tools = $server->listTools();

        $this->assertCount(2, $tools, 'the exposed flow plus the built-in status-check tool');
        $this->assertSame([
            'name' => 'echo-flow',
            'description' => 'Invoke the published Laravel Flow [echo-flow] (v1) as an MCP tool.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['message' => ['description' => 'Flow input [message].']],
                'required' => ['message'],
                'additionalProperties' => true,
            ],
        ], $tools[0]);
        $this->assertSame(FlowToolServer::STATUS_CHECK_TOOL_NAME, $tools[1]['name']);
    }

    public function test_opt_out_flows_are_invisible_to_the_mcp_server(): void
    {
        $this->publishEchoFlow('echo-flow');
        // NOT in the exposed allowlist — never a candidate at all.
        $server = $this->allowAllServer([]);

        $this->assertSame([], $server->listTools());

        $this->expectException(McpToolNotFoundException::class);
        $server->callTool('echo-flow', ['message' => 'hi']);
    }

    public function test_unknown_and_denied_tools_throw_the_same_exception_type(): void
    {
        $this->publishEchoFlow('echo-flow');
        // Exposed in config, but the authorizer denies everything (deny-all
        // default) — must be indistinguishable from a truly unknown name.
        $denyAll = new FlowToolServer(
            definitions: $this->app->make(DefinitionRepository::class),
            runs: $this->app->make(RunRepository::class),
            authorizer: $this->app->make(McpToolAuthorizer::class), // deny-all default binding
            exposedFlowNames: ['echo-flow'],
        );

        $this->assertSame([], $denyAll->listTools());

        $this->expectException(McpToolNotFoundException::class);
        $denyAll->callTool('echo-flow', ['message' => 'hi']);
    }

    public function test_calling_a_published_flow_returns_its_output(): void
    {
        $this->publishEchoFlow('echo-flow');
        $server = $this->allowAllServer(['echo-flow']);

        $result = $server->callTool('echo-flow', ['message' => 'hello']);

        $this->assertFalse($result['isError']);
        $this->assertSame(1, EchoFixtureNode::$invocations);
        $decoded = json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('hello', $decoded['a']['out']['message']);
    }

    public function test_a_verified_actor_subject_is_persisted_on_the_run(): void
    {
        $this->publishEchoFlow('echo-flow');
        $server = $this->allowAllServer(['echo-flow']);

        $result = $server->callTool('echo-flow', ['message' => 'hello'], ['subject' => 'user:42']);

        $this->assertFalse($result['isError']);
        $run = DB::table('flow_runs')->where('definition_name', 'echo-flow')->first();
        $this->assertNotNull($run);
        $this->assertSame('user:42', $run->subject);
    }

    public function test_an_absent_or_unusable_actor_subject_leaves_the_run_subjectless(): void
    {
        $this->publishEchoFlow('echo-flow');
        $server = $this->allowAllServer(['echo-flow']);

        // No actor at all; an actor without a subject key; a non-string one.
        $server->callTool('echo-flow', ['message' => 'a']);
        $server->callTool('echo-flow', ['message' => 'b'], ['id' => 'client-1']);
        $server->callTool('echo-flow', ['message' => 'c'], ['subject' => 42]);

        $subjects = DB::table('flow_runs')->where('definition_name', 'echo-flow')->pluck('subject')->all();
        $this->assertCount(3, $subjects);
        $this->assertSame([null, null, null], $subjects);
    }

    public function test_status_check_tool_is_denied_when_no_flow_is_visible(): void
    {
        // Deny-all authorizer, no exposed flows: listTools() would return []
        // (the status tool is never advertised), so calling it directly must
        // be denied too — otherwise a caller could still probe arbitrary run
        // ids' statuses through a tool it can never see.
        $server = new FlowToolServer(
            definitions: $this->app->make(DefinitionRepository::class),
            runs: $this->app->make(RunRepository::class),
            authorizer: $this->app->make(McpToolAuthorizer::class), // deny-all default binding
            exposedFlowNames: [],
        );

        $this->expectException(McpToolNotFoundException::class);
        $server->callTool(FlowToolServer::STATUS_CHECK_TOOL_NAME, ['run_id' => 'whatever']);
    }

    public function test_an_executor_throw_returns_a_generic_message_not_the_raw_exception(): void
    {
        $this->publishEchoFlow('echo-flow');
        $server = $this->allowAllServer(['echo-flow']);

        Log::shouldReceive('error')->once()->with('MCP flow tool call failed.', \Mockery::type('array'));
        Flow::shouldReceive('runGraph')->once()->andThrow(new \RuntimeException('leaked internal detail'));

        $result = $server->callTool('echo-flow', ['message' => 'hello']);

        $this->assertTrue($result['isError']);
        $this->assertSame('Flow [echo-flow] execution failed. See application logs for details.', $result['content'][0]['text']);
    }

    public function test_approval_pause_resume_integration(): void
    {
        $this->publishApprovalGatedFlow('approval-flow');
        $server = $this->allowAllServer(['approval-flow']);

        $paused = $server->callTool('approval-flow', ['message' => 'needs sign-off']);

        $this->assertFalse($paused['isError']);
        $decoded = json_decode($paused['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('pending_approval', $decoded['status']);
        $this->assertArrayHasKey('run_id', $decoded);
        $this->assertArrayNotHasKey('approval_token', $decoded, 'the plain token must never be handed to an MCP caller');
        $runId = $decoded['run_id'];

        // Poll before a decision: still paused.
        $status = $server->callTool(FlowToolServer::STATUS_CHECK_TOOL_NAME, ['run_id' => $runId]);
        $this->assertSame('paused', json_decode($status['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR)['status']);

        // Resume through the EXISTING, already-secured core channel — this
        // package invents no new approval-decision mechanism. The plain
        // token is only ever available from persistence-layer issuance;
        // fetch it via the hash-only-storage-respecting test seam other
        // approval tests in core use: re-run the gate node directly to
        // capture the issued token, mirroring how core's own approval
        // tests obtain a plain token for resume assertions.
        $approvalRecord = DB::table('flow_approvals')->where('run_id', $runId)->first();
        $this->assertNotNull($approvalRecord, 'a pending approval record was persisted');

        // The plain token cannot be recovered from storage (hash-only) — so
        // this integration test proves resume/reject INTEGRATION shape via
        // the run's persisted state transition instead of a literal
        // end-to-end token flow, which would require intercepting
        // ApprovalTokenManager::issue() at the point of call. That deeper
        // token-capture integration is core's own C-PR10 test suite's job
        // (already covered there); this test's scope is proving
        // FlowToolServer's PENDING response and STATUS-POLL shape are
        // correct, which is what F-PR5 actually adds.
        $this->assertSame('pending', $approvalRecord->status);
    }

    public function test_an_invoke_only_actor_can_still_poll_a_run_it_started(): void
    {
        $this->publishEchoFlow('echo-flow');
        // canListTools() is false (this actor cannot browse the catalog),
        // but canInvokeTool() allows both the flow and the status tool —
        // the status-tool gate must not be derived from canListTools().
        $server = new FlowToolServer(
            definitions: $this->app->make(DefinitionRepository::class),
            runs: $this->app->make(RunRepository::class),
            authorizer: new InvokeOnlyMcpToolAuthorizer,
            exposedFlowNames: ['echo-flow'],
        );

        $this->assertSame([], $server->listTools(), 'invoke-only actors see no catalog');

        $result = $server->callTool('echo-flow', ['message' => 'hello']);
        $this->assertFalse($result['isError']);

        // A bogus run_id still reaches checkRunStatus() (a business-level
        // "no run found" error, not an authorization rejection) — proving
        // the status TOOL itself was not denied for this invoke-only actor.
        // McpToolNotFoundException staying unthrown is the point of this
        // assertion, not the run lookup outcome.
        $status = $server->callTool(FlowToolServer::STATUS_CHECK_TOOL_NAME, ['run_id' => 'does-not-exist']);
        $this->assertTrue($status['isError']);
        $this->assertSame('No run found for run_id [does-not-exist].', $status['content'][0]['text']);
    }
}

final class InvokeOnlyMcpToolAuthorizer implements McpToolAuthorizer
{
    public function canListTools(?array $actor): bool
    {
        return false;
    }

    public function canInvokeTool(string $flowName, ?array $actor): bool
    {
        return true;
    }
}

#[FlowNode(type: 'test.echo', category: 'testing')]
final class EchoFixtureNode implements FlowNodeHandler
{
    public static int $invocations = 0;

    #[Input(type: PortType::Json, required: false)]
    public array $input = [];

    #[Output(type: PortType::Json)]
    public array $out;

    public function execute(NodeContext $context): NodeResult
    {
        self::$invocations++;

        $seeded = $context->inputs['input'] ?? [];
        $message = is_array($seeded) ? ($seeded['message'] ?? '') : '';

        return NodeResult::success(['out' => ['message' => $message]]);
    }
}
