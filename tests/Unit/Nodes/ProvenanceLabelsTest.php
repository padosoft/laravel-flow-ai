<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Nodes;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Graph\GraphValidator;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Node\PortProvenance;
use Padosoft\LaravelFlow\Provenance\TaintAnalyzer;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;

/**
 * The labels only matter if they stop the graph someone would actually
 * draw. These tests are that graph.
 *
 * The chain under test is the one that makes indirect prompt injection a
 * real exploit rather than a curiosity: a model reads attacker-controlled
 * text, the model's output is wired onward, and somewhere downstream it
 * decides what gets executed.
 */
final class ProvenanceLabelsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowAIServiceProvider::class];
    }

    public function test_model_output_is_labelled_untrusted(): void
    {
        $registry = $this->app->make(NodeRegistry::class);

        foreach (['ai.llm.prompt', 'ai.agent.bounded', 'ai.mcp.tool'] as $type) {
            $result = $registry->get($type)->output('result');

            $this->assertNotNull($result, "[{$type}] has no result port.");
            $this->assertSame(
                PortProvenance::Untrusted,
                $result->provenance,
                "[{$type}] emits someone else's words and must say so.",
            );
        }
    }

    public function test_the_ports_that_choose_what_runs_refuse_untrusted_data(): void
    {
        $registry = $this->app->make(NodeRegistry::class);

        $expectations = [
            'ai.llm.prompt' => ['model', 'systemPrompt'],
            'ai.agent.bounded' => ['model', 'systemPrompt', 'command', 'args'],
            'ai.mcp.tool' => ['command', 'args', 'tool'],
        ];

        foreach ($expectations as $type => $portKeys) {
            foreach ($portKeys as $portKey) {
                $port = $registry->get($type)->input($portKey);

                $this->assertNotNull($port, "[{$type}] has no input [{$portKey}].");
                $this->assertTrue(
                    $port->requiresTrusted,
                    "[{$type}].{$portKey} decides what runs and must refuse untrusted data.",
                );
            }
        }
    }

    public function test_tool_arguments_deliberately_accept_untrusted_data(): void
    {
        // Pinned as a decision, not an oversight. Filling in the parameters
        // of a tool the AUTHOR chose is what tool use IS; forbidding it
        // would make the check something people switch off. The line is:
        // the model may fill in parameters, never select the operation.
        $arguments = $this->app->make(NodeRegistry::class)->get('ai.mcp.tool')->input('arguments');

        $this->assertNotNull($arguments);
        $this->assertFalse($arguments->requiresTrusted);
    }

    public function test_a_model_cannot_be_wired_to_choose_what_gets_executed(): void
    {
        // `args` is the argv of the process this node spawns. A model
        // filling that in is arbitrary code execution, and it is
        // type-compatible with the model's own output, so nothing but the
        // taint check stands in the way.
        //
        // Note the port: `args`, not `arguments`. Deciding what runs is the
        // thing the model may never do; supplying a chosen tool's
        // parameters is the thing it is there for.
        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/requires trusted data but receives untrusted data originating at \[llm\.result\]/');

        $this->validator()->validate(new GraphDefinition(
            [
                new GraphNode('llm', 'ai.llm.prompt', ['template' => 'x', 'model' => 'gpt-5']),
                new GraphNode('mcp', 'ai.mcp.tool', ['command' => 'npx', 'tool' => 'read']),
            ],
            [new Connection('llm', 'result', 'mcp', 'args')],
        ));
    }

    public function test_the_port_type_system_blocks_the_tool_name_case_before_taint_does(): void
    {
        // Worth pinning so nobody "fixes" it later: a model's `result` is
        // Json and `tool` is Text, so that wire is refused on type grounds
        // and never reaches the taint pass at all (which is correct — taint
        // does not speculate about a structure that does not hold). The
        // `requiresTrusted` on `tool` is what catches the same intent
        // routed through an intermediate node that extracts a string.
        try {
            $this->validator()->validate(new GraphDefinition(
                [
                    new GraphNode('llm', 'ai.llm.prompt', ['template' => 'x', 'model' => 'gpt-5']),
                    new GraphNode('mcp', 'ai.mcp.tool', ['command' => 'npx']),
                ],
                [new Connection('llm', 'result', 'mcp', 'tool')],
            ));
            $this->fail('Expected InvalidGraphException.');
        } catch (InvalidGraphException $e) {
            $this->assertStringContainsString('cannot feed input type [text]', implode(' ', $e->violations()));
        }
    }

    public function test_a_model_cannot_be_wired_to_write_its_own_system_prompt(): void
    {
        // The subtle one. Nothing is "executed" here — but a model that
        // authors the system prompt of the next call has been handed its
        // own instructions, which is every bit as much an escalation.
        try {
            $this->validator()->validate(new GraphDefinition(
                [
                    new GraphNode('a', 'ai.llm.prompt', ['template' => 'x', 'model' => 'gpt-5']),
                    new GraphNode('b', 'ai.llm.prompt', ['template' => 'y', 'model' => 'gpt-5']),
                ],
                [new Connection('a', 'result', 'b', 'systemPrompt')],
            ));
            $this->fail('A model was allowed to author the next call\'s system prompt.');
        } catch (InvalidGraphException $e) {
            $this->assertStringContainsString('systemPrompt', implode(' ', $e->violations()));
        }
    }

    public function test_an_mcp_tool_result_is_untrusted_two_hops_later(): void
    {
        // A remote tool's response is a remote server's words. Passing it
        // through a model does not make it ours, so it must still be
        // refused where it would decide what runs.
        $graph = new GraphDefinition(
            [
                new GraphNode('mcp', 'ai.mcp.tool', ['command' => 'npx', 'tool' => 'read']),
                new GraphNode('llm', 'ai.llm.prompt', ['template' => 'x', 'model' => 'gpt-5']),
                new GraphNode('agent', 'ai.agent.bounded', ['task' => 't', 'model' => 'gpt-5', 'command' => 'npx']),
            ],
            [
                new Connection('mcp', 'result', 'llm', 'variables'),
                new Connection('llm', 'result', 'agent', 'args'),
            ],
        );

        $analyzer = $this->app->make(TaintAnalyzer::class);
        $map = $analyzer->analyze($graph);
        $violations = $analyzer->violations($graph, $map);

        $this->assertCount(1, $violations);

        // The blamed origin is the LLM, not the MCP result — and that is
        // the useful answer, not a lost one. `llm.result` is a declared
        // source: it would be untrusted with nothing wired into it at all,
        // so sanitizing the MCP result upstream would fix nothing. The
        // origin names where a sanitizer would actually have to go.
        $this->assertSame('llm.result', $violations[0]->path->origin());
        $this->assertSame('llm.result -> agent.args', $violations[0]->path->render());

        // The upstream hop is not discarded, just not blamed: the map still
        // records that the tool's response reached the model.
        $this->assertSame(
            'mcp.result -> llm.variables',
            $map->pathToInput('llm', 'variables')?->render(),
        );
    }

    public function test_the_ordinary_summarise_then_refine_chain_still_validates(): void
    {
        // The check has to leave the normal case alone, or people turn it
        // off. Feeding one completion into the next prompt is exactly that
        // normal case.
        $this->validator()->validate(new GraphDefinition(
            [
                new GraphNode('a', 'ai.llm.prompt', ['template' => 'summarise', 'model' => 'gpt-5']),
                new GraphNode('b', 'ai.llm.prompt', ['template' => 'refine', 'model' => 'gpt-5']),
            ],
            [new Connection('a', 'result', 'b', 'variables')],
        ));

        $this->addToAssertionCount(1);
    }

    public function test_an_author_configured_tool_call_validates(): void
    {
        // Config literals are authored, so a graph where the author picked
        // the server and the tool passes — even though the node's own
        // result is untrusted.
        $this->validator()->validate(new GraphDefinition(
            [new GraphNode('mcp', 'ai.mcp.tool', ['command' => 'npx', 'tool' => 'read', 'arguments' => ['path' => 'a.txt']])],
            [],
        ));

        $this->addToAssertionCount(1);
    }

    private function validator(): GraphValidator
    {
        return $this->app->make(GraphValidator::class);
    }
}
