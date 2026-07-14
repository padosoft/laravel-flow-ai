<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Builder;

use Padosoft\LaravelFlow\Graph\GraphValidator;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeDefinitionFactory;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlowAI\Builder\FlowBuilderService;
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use Padosoft\LaravelFlowAI\Guardrails\PolicyDeniedException;
use Padosoft\LaravelFlowAI\Llm\FakeDriver;
use Padosoft\LaravelFlowAI\Llm\LlmRequest;
use Padosoft\LaravelFlowAI\Llm\LlmResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FlowBuilderServiceTest extends TestCase
{
    private function registry(): NodeRegistry
    {
        $registry = new NodeRegistry(new NodeDefinitionFactory);
        $registry->registerMany([SourceFixtureNode::class, SinkFixtureNode::class, BoolSinkFixtureNode::class]);

        return $registry;
    }

    private function service(FakeDriver $driver, ?NodeRegistry $registry = null): FlowBuilderService
    {
        $registry ??= $this->registry();

        return new FlowBuilderService($driver, $registry, new GraphValidator($registry));
    }

    /**
     * Approximates the plan's property-test gate criterion ("for a wide
     * range of fake LLM outputs ... the service's output ALWAYS either
     * passes GraphValidator or returns a typed failure, never an invalid
     * draft escapes") with a comprehensive example table rather than a real
     * property-testing library — none is a dev dependency anywhere in this
     * program yet, and adding an unvetted one for a single PR mirrors the
     * F-PR4 decision to not add a new dependency without a human
     * evaluating it first. Every case below is a distinct FAILURE MODE
     * category the gate criterion names explicitly (malformed JSON, valid
     * JSON but an invalid graph: dangling connection / cycle / unknown node
     * type), plus every additional invariant GraphNode/Connection/
     * GraphDefinition/GraphValidator themselves enforce.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function llmOutputCases(): array
    {
        return [
            'well-formed graph' => [
                '{"nodes":[{"id":"a","type":"test.source"},{"id":"b","type":"test.sink"}],"connections":[{"from_node":"a","from_port":"out","to_node":"b","to_port":"in"}]}',
                true,
            ],
            'well-formed single-node graph, no connections' => [
                '{"nodes":[{"id":"a","type":"test.source"}],"connections":[]}',
                true,
            ],
            'not json at all' => ['not json at all', false],
            'valid json but a top-level array, not an object' => ['[1,2,3]', false],
            'valid json object but nodes/connections missing entirely' => ['{}', false],
            'zero nodes' => ['{"nodes":[],"connections":[]}', false],
            'dangling connection references an unknown node' => [
                '{"nodes":[{"id":"a","type":"test.source"}],"connections":[{"from_node":"a","from_port":"out","to_node":"ghost","to_port":"in"}]}',
                false,
            ],
            'a cycle' => [
                '{"nodes":[{"id":"a","type":"test.sink"},{"id":"b","type":"test.sink"}],"connections":[{"from_node":"a","from_port":"out","to_node":"b","to_port":"in"},{"from_node":"b","from_port":"out","to_node":"a","to_port":"in"}]}',
                false,
            ],
            'unknown node type' => [
                '{"nodes":[{"id":"a","type":"ai.does.not.exist"}],"connections":[]}',
                false,
            ],
            'duplicate node id' => [
                '{"nodes":[{"id":"a","type":"test.source"},{"id":"a","type":"test.sink"}],"connections":[]}',
                false,
            ],
            'connection onto an unknown input port' => [
                '{"nodes":[{"id":"a","type":"test.source"},{"id":"b","type":"test.sink"}],"connections":[{"from_node":"a","from_port":"out","to_node":"b","to_port":"no_such_port"}]}',
                false,
            ],
            'incompatible port types (Text output into Bool input)' => [
                '{"nodes":[{"id":"a","type":"test.source"},{"id":"b","type":"test.bool_sink"}],"connections":[{"from_node":"a","from_port":"out","to_node":"b","to_port":"flag"}]}',
                false,
            ],
            'empty node id' => [
                '{"nodes":[{"id":"","type":"test.source"}],"connections":[]}',
                false,
            ],
            'empty node type' => [
                '{"nodes":[{"id":"a","type":""}],"connections":[]}',
                false,
            ],
            'connection wiring a node to itself' => [
                '{"nodes":[{"id":"a","type":"test.source"}],"connections":[{"from_node":"a","from_port":"out","to_node":"a","to_port":"out"}]}',
                false,
            ],
            'nodes entry is not an object' => [
                '{"nodes":["not-an-object"],"connections":[]}',
                false,
            ],
            'nodes entry is a JSON array, not an object' => [
                '{"nodes":[[1,2,3]],"connections":[]}',
                false,
            ],
            'connections entry is a JSON array, not an object' => [
                '{"nodes":[{"id":"a","type":"test.source"}],"connections":[[1,2,3]]}',
                false,
            ],
            'a node id that is an array, not a string' => [
                '{"nodes":[{"id":[],"type":"test.source"}],"connections":[]}',
                false,
            ],
            'a node type that is an array, not a string' => [
                '{"nodes":[{"id":"a","type":["test.source"]}],"connections":[]}',
                false,
            ],
            'a connection field that is an array, not a string' => [
                '{"nodes":[{"id":"a","type":"test.source"},{"id":"b","type":"test.sink"}],"connections":[{"from_node":"a","from_port":["out"],"to_node":"b","to_port":"in"}]}',
                false,
            ],
        ];
    }

    #[DataProvider('llmOutputCases')]
    public function test_the_output_always_either_validates_or_returns_a_typed_failure(string $llmContent, bool $expectedSuccess): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: $llmContent, model: 'claude-x', promptTokens: 10, completionTokens: 10),
        ]);
        $service = $this->service($driver);

        $result = $service->build('build me a flow', 'claude-x');

        $this->assertSame($expectedSuccess, $result->success);

        if ($expectedSuccess) {
            $this->assertNotNull($result->graph);
            $this->assertSame([], $result->errors);
        } else {
            $this->assertNull($result->graph);
            $this->assertNotSame([], $result->errors, 'a failure always carries at least one concrete reason');
        }
    }

    public function test_the_prompt_describes_the_real_node_catalog(): void
    {
        $driver = new FakeDriver([
            new LlmResponse(content: '{"nodes":[{"id":"a","type":"test.source"}],"connections":[]}', model: 'claude-x', promptTokens: 1, completionTokens: 1),
        ]);
        $service = $this->service($driver);

        $service->build('anything', 'claude-x');

        $this->assertStringContainsString('test.source', $driver->requests()[0]->prompt);
        $this->assertStringContainsString('test.sink', $driver->requests()[0]->prompt);
        $this->assertStringContainsString('anything', $driver->requests()[0]->prompt);
    }

    public function test_a_policy_denial_returns_a_typed_failure_not_an_uncaught_exception(): void
    {
        $registry = $this->registry();
        $denyingClient = new class implements LlmClient
        {
            public function complete(LlmRequest $request): LlmResponse
            {
                throw new PolicyDeniedException('egress denied for ai.flow.builder');
            }
        };
        $service = new FlowBuilderService($denyingClient, $registry, new GraphValidator($registry));

        $result = $service->build('build me a flow', 'claude-x');

        $this->assertFalse($result->success);
        $this->assertNull($result->graph);
        $this->assertSame(['egress denied for ai.flow.builder'], $result->errors);
    }
}

#[FlowNode(type: 'test.source', category: 'testing')]
final class SourceFixtureNode implements FlowNodeHandler
{
    #[Output(type: PortType::Text)]
    public string $out;

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::success(['out' => 'value']);
    }
}

#[FlowNode(type: 'test.sink', category: 'testing')]
final class SinkFixtureNode implements FlowNodeHandler
{
    #[Input(type: PortType::Text, required: false)]
    public string $in = '';

    #[Output(type: PortType::Text)]
    public string $out;

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::success(['out' => 'value']);
    }
}

#[FlowNode(type: 'test.bool_sink', category: 'testing')]
final class BoolSinkFixtureNode implements FlowNodeHandler
{
    #[Input(type: PortType::Bool, required: false)]
    public bool $flag = false;

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::success([]);
    }
}
