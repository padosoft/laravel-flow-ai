<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Nodes;

use InvalidArgumentException;
use JsonException;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use Padosoft\LaravelFlowAI\Guardrails\PolicyDeniedException;
use Padosoft\LaravelFlowAI\Guardrails\PolicyEngine;
use Padosoft\LaravelFlowAI\Llm\LlmRequest;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpConnectionException;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpToolExecutionException;
use Padosoft\LaravelFlowAI\Mcp\FlowToolServer;
use Padosoft\LaravelFlowAI\Mcp\McpClient;
use Padosoft\LaravelFlowAI\Mcp\Transport\McpTransportFactory;
use Padosoft\LaravelFlowAI\Nodes\Exceptions\AgentBudgetExhaustedException;
use Padosoft\LaravelFlowAI\Nodes\Exceptions\AgentToolNotAllowedException;
use stdClass;

/**
 * An LLM+tools loop bounded by hard budgets (iterations, total tokens, and —
 * only when a cost rate is configured, see the constructor — cost), a tool
 * ALLOWLIST (empty by default: deny-by-default, matching this program's
 * `DenyAllAuthorizer`/`DenyAllMcpToolAuthorizer` posture — an unconfigured
 * agent may call NOTHING), and an approval "escape hatch" that reuses
 * EXISTING infrastructure rather than inventing new pause semantics: if a
 * called tool is itself a flow exposed via {@see FlowToolServer}
 * and that flow pauses on an `ApprovalGateNode`, the tool call comes back as
 * F-PR5's `pending_approval` envelope — this node recognizes that shape and
 * halts its OWN loop immediately (a plain `NodeResult::success()` carrying
 * the run id), rather than trying to keep ITSELF paused waiting on a
 * separate flow run's resolution. The human resolves that inner run through
 * the already-secured core channels (`Flow::resume()`/`Flow::reject()`, the
 * `flow:approve`/`flow:reject` CLI, a companion dashboard) exactly as F-PR5
 * designed — this node adds no new approval-decision mechanism.
 *
 * One MCP connection is opened per `execute()` call (not per tool call
 * within the loop — a bounded agent's whole loop IS one node execution, so
 * this is the natural granularity) and closed in a `finally` block.
 *
 * Each iteration asks the model for exactly one structured decision — call a
 * tool or give a final answer — via the SAME `{}`-object self-repair
 * validation this package established in {@see LlmPromptNode}: a
 * non-JSON or wrongly-shaped response consumes one iteration and feeds the
 * parse error back into the next prompt, rather than failing the whole node
 * on the first malformed turn.
 *
 * Honors `$context->dryRun`: a dry run never calls the LLM or spawns the MCP
 * server, returning `NodeResult::dryRunSkipped()` instead.
 *
 * @api
 */
#[FlowNode(
    type: 'ai.agent.bounded',
    category: 'ai',
    description: 'LLM+tools loop bounded by token/cost/iteration budgets, a tool allowlist, and an approval escape hatch.',
)]
final class BoundedAgentNode implements FlowNodeHandler
{
    private const DEFAULT_MAX_ITERATIONS = 5;

    private const DEFAULT_MAX_TOTAL_TOKENS = 4000;

    #[Input(type: PortType::Text, required: true)]
    public string $task = '';

    #[Input(type: PortType::Text, required: true)]
    public string $model = '';

    #[Input(type: PortType::Text, required: false)]
    public string $systemPrompt = '';

    /** @var array<string, mixed> */
    #[Input(type: PortType::Json, required: false)]
    public array $variables = [];

    #[Input(type: PortType::Text, required: true)]
    public string $command = '';

    /** @var list<string> */
    #[Input(type: PortType::Json, required: false)]
    public array $args = [];

    /** @var array<string, mixed> */
    #[Output(type: PortType::Json)]
    public array $result;

    /**
     * @param  list<string>  $allowedTools  empty = NO tool may be called (deny-by-default — unlike `PolicyEngine`'s "empty = unrestricted" gates, an unconfigured agent's own action surface defaults to nothing, not everything)
     * @param  float|null  $maxCostUsd  cost budget in USD; a no-op unless BOTH this and `$costPerThousandTokens` are set — `LlmResponse` carries token counts, not cost, so cost enforcement needs an explicit rate this package has no built-in pricing table for
     */
    public function __construct(
        private readonly LlmClient $client,
        private readonly McpTransportFactory $transportFactory,
        private readonly array $allowedTools = [],
        private readonly int $maxIterations = self::DEFAULT_MAX_ITERATIONS,
        private readonly int $maxTotalTokens = self::DEFAULT_MAX_TOTAL_TOKENS,
        private readonly ?float $maxCostUsd = null,
        private readonly ?float $costPerThousandTokens = null,
        private readonly ?PolicyEngine $policy = null,
        private readonly ?PayloadRedactor $redactor = null,
    ) {
        if ($this->maxIterations < 1) {
            throw new InvalidArgumentException("BoundedAgentNode maxIterations must be at least 1, got {$this->maxIterations}.");
        }

        if ($this->maxTotalTokens < 1) {
            throw new InvalidArgumentException("BoundedAgentNode maxTotalTokens must be at least 1, got {$this->maxTotalTokens}.");
        }
    }

    public function execute(NodeContext $context): NodeResult
    {
        if ($context->dryRun) {
            return NodeResult::dryRunSkipped();
        }

        $task = (string) ($context->inputs['task'] ?? '');
        $model = (string) ($context->inputs['model'] ?? '');
        $systemPrompt = (string) ($context->inputs['systemPrompt'] ?? '');
        /** @var array<string, mixed> $variables */
        $variables = is_array($context->inputs['variables'] ?? null) ? $context->inputs['variables'] : [];
        $command = (string) ($context->inputs['command'] ?? '');
        /** @var list<string> $args */
        $args = array_values(array_filter((array) ($context->inputs['args'] ?? []), 'is_string'));

        if ($this->redactor !== null) {
            $variables = $this->redactor->redact($variables);
        }

        try {
            $task = $this->render($task, $variables);
        } catch (JsonException $e) {
            return NodeResult::failed($e);
        }

        if ($this->policy !== null) {
            $decision = $this->policy->authorize('ai.agent.bounded', "stdio:{$command}");

            if (! $decision->allowed) {
                return NodeResult::failed(new PolicyDeniedException((string) $decision->reason));
            }
        }

        $client = new McpClient($this->transportFactory->stdio($command, $args));

        try {
            return $this->runLoop($client, $task, $model, $systemPrompt);
        } finally {
            $client->close();
        }
    }

    private function runLoop(McpClient $client, string $task, string $model, string $systemPrompt): NodeResult
    {
        $tools = $this->describeAllowedTools($client);
        $transcript = [];
        $totalPromptTokens = 0;
        $totalCompletionTokens = 0;
        $totalCostUsd = 0.0;
        $lastError = null;

        for ($iteration = 1; $iteration <= $this->maxIterations; $iteration++) {
            $budgetViolation = $this->checkBudgets($iteration, $totalPromptTokens + $totalCompletionTokens, $totalCostUsd);

            if ($budgetViolation !== null) {
                return NodeResult::failed($budgetViolation);
            }

            $request = new LlmRequest(
                prompt: $this->renderIterationPrompt($task, $tools, $transcript, $lastError),
                model: $model,
                systemPrompt: $systemPrompt !== '' ? $systemPrompt : null,
                responseSchema: ['type' => 'object'],
            );

            try {
                $response = $this->client->complete($request);
            } catch (PolicyDeniedException $e) {
                // Never retried, same posture as LlmPromptNode: a policy
                // denial is an infra/permission concern, not a self-repairable
                // one, and retrying would just deny identically every time.
                return NodeResult::failed($e);
            }

            $totalPromptTokens += $response->promptTokens;
            $totalCompletionTokens += $response->completionTokens;
            $totalCostUsd += $this->costOf($response->promptTokens + $response->completionTokens);

            [$decision, $lastError] = $this->tryDecodeDecision($response->content);

            if ($decision === null) {
                $transcript[] = ['iteration' => $iteration, 'type' => 'invalid_decision', 'reason' => $lastError];

                continue;
            }

            $action = (string) ($decision['action'] ?? '');

            if ($action === 'final_answer') {
                return $this->success('final_answer', [
                    'answer' => (string) ($decision['answer'] ?? ''),
                ], $transcript, $model, $totalPromptTokens, $totalCompletionTokens);
            }

            if ($action !== 'call_tool') {
                $lastError = "unrecognized action [{$action}] — expected \"call_tool\" or \"final_answer\"";
                $transcript[] = ['iteration' => $iteration, 'type' => 'invalid_decision', 'reason' => $lastError];

                continue;
            }

            $tool = (string) ($decision['tool'] ?? '');
            /** @var array<string, mixed> $arguments */
            $arguments = is_array($decision['arguments'] ?? null) ? $decision['arguments'] : [];

            if ($tool === '') {
                // A missing/empty tool name is a malformed DECISION, not a
                // security violation — self-repairable like any other
                // wrongly-shaped response, not a hard allowlist halt (an
                // empty string is never actually in $this->allowedTools, so
                // without this check it would misleadingly present as one).
                $lastError = 'call_tool decision is missing a "tool" name';
                $transcript[] = ['iteration' => $iteration, 'type' => 'invalid_decision', 'reason' => $lastError];

                continue;
            }

            if (! in_array($tool, $this->allowedTools, true)) {
                return NodeResult::failed(new AgentToolNotAllowedException(
                    $tool,
                    "BoundedAgentNode: tool [{$tool}] is not in the configured allowlist; the call was blocked before it happened.",
                ));
            }

            try {
                $outcome = $this->callTool($client, $iteration, $tool, $arguments, $transcript);
            } catch (McpConnectionException $e) {
                // Unlike a tool-reported failure (caught inside callTool()
                // and fed back into the transcript for the model to see),
                // the SERVER itself being unreachable is not something
                // another loop iteration could fix — halt immediately.
                return NodeResult::failed($e);
            }

            if ($outcome !== null) {
                return $this->success('pending_approval', $outcome, $transcript, $model, $totalPromptTokens, $totalCompletionTokens);
            }

            $lastError = null;
        }

        return NodeResult::failed(new AgentBudgetExhaustedException(
            'iterations',
            "BoundedAgentNode: exhausted {$this->maxIterations} iteration(s) without a final answer.",
        ));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  list<array<string, mixed>>  $transcript
     * @return array<string, mixed>|null a pending-approval payload if the tool call paused on an inner approval gate, null to keep looping
     */
    private function callTool(McpClient $client, int $iteration, string $tool, array $arguments, array &$transcript): ?array
    {
        if ($this->redactor !== null) {
            $arguments = $this->redactor->redact($arguments);
        }

        try {
            $content = $client->callTool($tool, $arguments);
        } catch (McpToolExecutionException $e) {
            // A tool-reported failure is fed back into the transcript for
            // the model to see and try something else on the next iteration
            // — a self-repairable data problem, not a connection failure
            // (McpConnectionException — the server itself unreachable — is
            // deliberately left uncaught here and handled by the caller,
            // since retrying within this loop would not fix that).
            $transcript[] = ['iteration' => $iteration, 'type' => 'tool_error', 'tool' => $tool, 'arguments' => $arguments, 'error' => $e->getMessage()];

            return null;
        }

        $pending = $this->pendingApproval($content);

        if ($pending !== null) {
            $transcript[] = ['iteration' => $iteration, 'type' => 'tool_pending_approval', 'tool' => $tool, 'arguments' => $arguments, 'run_id' => $pending['run_id'] ?? null];

            return $pending;
        }

        $transcript[] = ['iteration' => $iteration, 'type' => 'tool_result', 'tool' => $tool, 'arguments' => $arguments, 'result' => $content];

        return null;
    }

    /**
     * Recognizes F-PR5's `FlowToolServer::pendingApprovalResult()` envelope
     * shape (`{status: 'pending_approval', run_id, message}` as the text of
     * the tool result's first content item) — the only signal this node
     * needs to treat a tool call as a halting approval pause rather than an
     * ordinary result to feed back into the next iteration.
     *
     * @param  list<mixed>  $content
     * @return array<string, mixed>|null
     */
    private function pendingApproval(array $content): ?array
    {
        $first = $content[0] ?? null;

        if (! is_array($first) || ! isset($first['text']) || ! is_string($first['text'])) {
            return null;
        }

        try {
            $decoded = json_decode($first['text'], false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! ($decoded instanceof stdClass) || ($decoded->status ?? null) !== 'pending_approval') {
            return null;
        }

        /** @var array<string, mixed> $value */
        $value = (array) $decoded;

        return $value;
    }

    private function checkBudgets(int $iteration, int $tokensSoFar, float $costSoFar): ?AgentBudgetExhaustedException
    {
        if ($iteration > $this->maxIterations) {
            return new AgentBudgetExhaustedException('iterations', "BoundedAgentNode: exhausted {$this->maxIterations} iteration(s) without a final answer.");
        }

        if ($tokensSoFar >= $this->maxTotalTokens) {
            return new AgentBudgetExhaustedException('tokens', "BoundedAgentNode: exhausted the {$this->maxTotalTokens}-token budget ({$tokensSoFar} used) before reaching a final answer.");
        }

        // Cost enforcement requires a RATE (costOf() cannot compute a real
        // cost without one, and always returns 0.0 in its absence) — gating
        // on $maxCostUsd alone would halt every run immediately once
        // maxCostUsd <= 0.0, even though no real cost was ever computed.
        if ($this->maxCostUsd !== null && $this->costPerThousandTokens !== null && $costSoFar >= $this->maxCostUsd) {
            return new AgentBudgetExhaustedException('cost', sprintf('BoundedAgentNode: exhausted the $%.4f cost budget ($%.4f used) before reaching a final answer.', $this->maxCostUsd, $costSoFar));
        }

        return null;
    }

    private function costOf(int $tokens): float
    {
        if ($this->costPerThousandTokens === null) {
            return 0.0;
        }

        return ($tokens / 1000) * $this->costPerThousandTokens;
    }

    /**
     * @return list<array{name: string, description: mixed, inputSchema: mixed}>
     */
    private function describeAllowedTools(McpClient $client): array
    {
        if ($this->allowedTools === []) {
            return [];
        }

        $tools = [];

        foreach ($client->listTools() as $tool) {
            $name = (string) ($tool['name'] ?? '');

            if (in_array($name, $this->allowedTools, true)) {
                $tools[] = [
                    'name' => $name,
                    'description' => $tool['description'] ?? '',
                    'inputSchema' => $tool['inputSchema'] ?? new stdClass,
                ];
            }
        }

        return $tools;
    }

    /**
     * @param  list<array{name: string, description: mixed, inputSchema: mixed}>  $tools
     * @param  list<array<string, mixed>>  $transcript
     */
    private function renderIterationPrompt(string $task, array $tools, array $transcript, ?string $lastError): string
    {
        $lines = [
            'You are a bounded autonomous agent. Task:',
            $task,
            '',
            'Available tools (call ONLY these, by exact name):',
            json_encode($tools, JSON_THROW_ON_ERROR),
            '',
            'Conversation so far (tool calls and their results, oldest first):',
            json_encode($transcript, JSON_THROW_ON_ERROR),
            '',
            'Respond with ONLY a JSON object, one of these two shapes:',
            '{"action":"call_tool","tool":"<name>","arguments":{...}}',
            '{"action":"final_answer","answer":"<your answer>"}',
        ];

        if ($lastError !== null) {
            $lines[] = '';
            $lines[] = "Your previous response was invalid: {$lastError}";
            $lines[] = 'Respond again with ONLY a valid JSON object in one of the two shapes above.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function render(string $template, array $variables): string
    {
        $pairs = [];

        foreach ($variables as $key => $value) {
            $pairs['{{'.$key.'}}'] = is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);
        }

        return strtr($template, $pairs);
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function tryDecodeDecision(string $content): array
    {
        try {
            // Same {} vs [] rationale as LlmPromptNode::tryDecodeObject():
            // decode non-associatively FIRST, purely to check the top-level
            // shape, so an empty JSON ARRAY can be rejected while a
            // legitimately empty `{}` still passes.
            $shape = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [null, "response was not valid JSON: {$e->getMessage()}"];
        }

        if (! ($shape instanceof stdClass)) {
            return [null, 'response was valid JSON but not an object (got '.get_debug_type($shape).')'];
        }

        // Decoded a SECOND time, associatively, for the actual value: a
        // shallow `(array) $shape` cast does not recurse into nested object
        // members (e.g. the "arguments" object of a call_tool decision)
        // silently leaving them as stdClass instead of array — this exact
        // bug already bit this program's MCP transport in F-PR4, see
        // docs/LESSON.md. `arguments` MUST be a real array here: it is
        // passed straight to McpClient::callTool(array $arguments).
        /** @var array<string, mixed> $value */
        $value = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return [$value, null];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $transcript
     */
    private function success(string $outcome, array $payload, array $transcript, string $model, int $promptTokens, int $completionTokens): NodeResult
    {
        if ($this->redactor !== null) {
            /** @var list<array<string, mixed>> $transcript */
            $transcript = $this->redactor->redact(['entries' => $transcript])['entries'];
        }

        return NodeResult::success(
            ['result' => ['outcome' => $outcome, ...$payload, 'transcript' => $transcript]],
            [
                'model' => $model,
                'tokens' => [
                    'prompt' => $promptTokens,
                    'completion' => $completionTokens,
                    'total' => $promptTokens + $completionTokens,
                ],
            ],
        );
    }
}
