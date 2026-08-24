<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp;

use Illuminate\Support\Facades\Log;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Graph\StoredDefinition;
use Padosoft\LaravelFlowAI\Contracts\McpToolAuthorizer;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpToolNotFoundException;
use Padosoft\LaravelFlowAI\Mcp\Transport\StdioMcpTransport;
use Padosoft\LaravelFlowAI\Nodes\McpClientNode;
use Throwable;

/**
 * Exposes a configured allowlist of PUBLISHED flows as MCP tools — server
 * SIDE (the inbound direction: receiving `tools/list`/`tools/call`-shaped
 * requests and returning MCP result envelopes), the mirror of
 * {@see McpClient} which is the OUTBOUND client
 * side {@see McpClientNode} uses. This class
 * does not itself listen on a socket or spawn a subprocess — it is a
 * request-HANDLING service a host application wires into whichever MCP
 * TRANSPORT (stdio, HTTP/SSE) it runs; that wiring is outside this package's
 * scope (this package's own MCP work has so far only needed a CLIENT
 * transport, per {@see StdioMcpTransport}'s
 * class doc).
 *
 * **Disabled by default**: only flow names present in the constructor's
 * `$exposedFlowNames` allowlist (config-driven — see
 * `config/laravel-flow-ai.php`'s `mcp.exposed_flows`, empty by default) are
 * EVER considered, and even a listed name is invisible unless BOTH a
 * PUBLISHED version currently exists for it AND {@see McpToolAuthorizer}
 * allows listing/invoking it — an opted-out, unpublished, or denied flow
 * never appears in `listTools()` and `callTool()` against its name throws
 * the SAME {@see McpToolNotFoundException} as a genuinely unknown name,
 * never a distinguishable "exists but denied" — an untrusted or
 * semi-trusted caller (an LLM agent, for instance) cannot discover a
 * flow's mere existence by probing tool names.
 *
 * A flow whose graph reaches an `ApprovalGateNode` and pauses does NOT hang
 * the tool call open — `callTool()` returns immediately with a structured
 * pending-approval result carrying the run id (never the plain approval
 * token itself: an MCP caller is not necessarily a trusted human operator,
 * and handing an approve/reject-capable secret to a semi-trusted agent
 * context would contradict this whole package's redaction/guardrails
 * posture). The built-in `flow.check_run_status` tool (always present
 * alongside at least one exposed flow) lets a caller poll a run id's
 * current state after a human approves/rejects through the EXISTING,
 * already-secured core channels (`Flow::resume()`/`Flow::reject()`, the
 * `flow:approve`/`flow:reject` CLI, or a companion dashboard) — this
 * package invents no new approval-decision channel.
 *
 * @api
 */
final class FlowToolServer
{
    public const STATUS_CHECK_TOOL_NAME = 'flow.check_run_status';

    /**
     * @var list<string>
     */
    private readonly array $exposedFlowNames;

    /**
     * @param  list<string>  $exposedFlowNames  candidate flow names this server MAY expose — see class doc for the full visibility gate. Deduplicated defensively: a host config that accidentally repeats a name must never produce a duplicate tool entry from `listTools()`.
     */
    public function __construct(
        private readonly DefinitionRepository $definitions,
        private readonly RunRepository $runs,
        private readonly McpToolAuthorizer $authorizer,
        array $exposedFlowNames = [],
    ) {
        $this->exposedFlowNames = array_values(array_unique($exposedFlowNames));
    }

    /**
     * @param  array<string, mixed>|null  $actor
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function listTools(?array $actor = null): array
    {
        if (! $this->authorizer->canListTools($actor)) {
            return [];
        }

        $tools = [];

        foreach ($this->exposedFlowNames as $name) {
            if (! $this->authorizer->canInvokeTool($name, $actor)) {
                continue;
            }

            $definition = $this->definitions->latest($name, StoredDefinition::STATUS_PUBLISHED);

            if ($definition === null) {
                continue;
            }

            $tools[] = $this->toolDescriptor($name, $definition);
        }

        if ($tools !== [] && $this->authorizer->canInvokeTool(self::STATUS_CHECK_TOOL_NAME, $actor)) {
            $tools[] = $this->statusCheckToolDescriptor();
        }

        return $tools;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>|null  $actor
     * @return array{content: list<array<string, mixed>>, isError: bool}
     *
     * @throws McpToolNotFoundException
     */
    public function callTool(string $name, array $arguments, ?array $actor = null): array
    {
        if ($name === self::STATUS_CHECK_TOOL_NAME) {
            // A direct, explicit authorization decision — same as any other
            // tool name — deliberately NOT derived from canListTools() or
            // from any single flow's own authorization: an "invoke-only"
            // actor (one who can call a specific flow but not browse the
            // catalog) must still be able to poll the run it just started.
            // See McpToolAuthorizer::canInvokeTool()'s doc for the host-side
            // contract this relies on.
            if (! $this->authorizer->canInvokeTool($name, $actor)) {
                throw new McpToolNotFoundException("Unknown MCP tool [{$name}].");
            }

            return $this->checkRunStatus($arguments);
        }

        if (! in_array($name, $this->exposedFlowNames, true) || ! $this->authorizer->canInvokeTool($name, $actor)) {
            throw new McpToolNotFoundException("Unknown MCP tool [{$name}].");
        }

        $definition = $this->definitions->latest($name, StoredDefinition::STATUS_PUBLISHED);

        if ($definition === null) {
            throw new McpToolNotFoundException("Unknown MCP tool [{$name}].");
        }

        $graph = (new GraphSerializer)->fromArray($definition->graph);

        try {
            $result = Flow::runGraph($graph, $arguments, $this->executionOptionsFor($actor), $name);
        } catch (Throwable $e) {
            // A throw out of the executor itself (not a node-level failure,
            // which the executor already turns into a Failed/PartiallySucceeded
            // roll-up) is still a TOOL-level failure from the caller's
            // perspective, not a protocol error — the tool name was valid,
            // invoking it just didn't work this time. The real message is
            // logged (server-side context for debugging) but never handed to
            // the caller: an MCP caller is semi-trusted at best, and an
            // internal exception message can carry details (paths, driver
            // errors, occasionally payload fragments) it has no business
            // seeing.
            Log::error('MCP flow tool call failed.', ['tool' => $name, 'exception' => $e]);

            return $this->errorResult("Flow [{$name}] execution failed. See application logs for details.");
        }

        if ($result->state === RunState::Paused) {
            return $this->pendingApprovalResult($result->runId);
        }

        if (! in_array($result->state, [RunState::Succeeded, RunState::PartiallySucceeded], true)) {
            $reason = $result->errors === [] ? "run ended {$result->state->value}" : implode('; ', $result->errors);

            return $this->errorResult($reason);
        }

        return [
            'content' => [['type' => 'text', 'text' => json_encode($result->nodeOutputs, JSON_THROW_ON_ERROR)]],
            'isError' => $result->state === RunState::PartiallySucceeded,
        ];
    }

    /**
     * Maps the transport-provided `$actor` onto the run's execution options —
     * the `subject` key, when present as a non-empty string, becomes the
     * run's persisted `flow_runs.subject` (WHO the run acts for, e.g.
     * `user:42`), so an MCP-initiated run is attributable end-to-end instead
     * of anonymous.
     *
     * `$actor` is HOST-provided metadata (the transport wiring authenticates
     * the caller and builds this array — the same trust already placed in it
     * by every {@see McpToolAuthorizer} check above), never something the
     * MCP caller controls directly: a `subject` here must come from a
     * VERIFIED identity (e.g. the `sub` of an introspected delegated access
     * token), not from tool-call params.
     *
     * @param  array<string, mixed>|null  $actor
     */
    private function executionOptionsFor(?array $actor): ?FlowExecutionOptions
    {
        $subject = $actor['subject'] ?? null;

        if (! is_string($subject) || trim($subject) === '') {
            // No verified subject -> no options object at all, keeping the
            // actorless path byte-for-byte the pre-existing behavior.
            return null;
        }

        return FlowExecutionOptions::make(subject: $subject);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    private function checkRunStatus(array $arguments): array
    {
        $runId = $arguments['run_id'] ?? null;

        if (! is_string($runId) || $runId === '') {
            return $this->errorResult('run_id is required.');
        }

        $run = $this->runs->find($runId);

        if ($run === null) {
            return $this->errorResult("No run found for run_id [{$runId}].");
        }

        return [
            'content' => [['type' => 'text', 'text' => json_encode(['run_id' => $runId, 'status' => $run->status], JSON_THROW_ON_ERROR)]],
            'isError' => false,
        ];
    }

    /**
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    private function pendingApprovalResult(string $runId): array
    {
        return [
            'content' => [['type' => 'text', 'text' => json_encode([
                'status' => 'pending_approval',
                'run_id' => $runId,
                'message' => 'This flow paused awaiting human approval. Poll ['.self::STATUS_CHECK_TOOL_NAME.'] with this run_id once approved/rejected through your operator channel (dashboard, flow:approve/flow:reject CLI).',
            ], JSON_THROW_ON_ERROR)]],
            'isError' => false,
        ];
    }

    /**
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    private function errorResult(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }

    /**
     * @return array{name: string, description: string, inputSchema: array<string, mixed>}
     */
    private function toolDescriptor(string $name, StoredDefinition $definition): array
    {
        return [
            'name' => $name,
            'description' => "Invoke the published Laravel Flow [{$name}] (v{$definition->version}) as an MCP tool.",
            'inputSchema' => $this->inputSchemaFor($definition),
        ];
    }

    /**
     * Derived from `metadata['required_inputs']` (the list of top-level
     * input keys a v1-builder-compiled flow declares via
     * `Flow::define()->withInput([...])` — see `FlowDefinition::toGraphDefinition()`
     * in core) when present. A hand-authored native graph with no such
     * metadata gets a maximally permissive schema (`additionalProperties:
     * true`, no `required`) rather than a precise one — deriving exact
     * per-key TYPES would require resolving every root node's handler
     * through core's `NodeRegistry`, which this server does not currently
     * do; documented here as a known v1 limitation, not silently pretended
     * away.
     *
     * @return array<string, mixed>
     */
    private function inputSchemaFor(StoredDefinition $definition): array
    {
        $requiredInputs = $definition->graph['metadata']['required_inputs'] ?? null;

        if (! is_array($requiredInputs) || $requiredInputs === []) {
            return ['type' => 'object', 'additionalProperties' => true];
        }

        $properties = [];

        foreach ($requiredInputs as $key) {
            if (is_string($key) && $key !== '') {
                $properties[$key] = ['description' => "Flow input [{$key}]."];
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => true,
        ];
    }

    /**
     * @return array{name: string, description: string, inputSchema: array<string, mixed>}
     */
    private function statusCheckToolDescriptor(): array
    {
        return [
            'name' => self::STATUS_CHECK_TOOL_NAME,
            'description' => 'Check the current status of a flow run by run id — use this to poll a run that returned a pending_approval result.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['run_id' => ['type' => 'string', 'description' => 'The run_id returned by a prior pending_approval tool result.']],
                'required' => ['run_id'],
                'additionalProperties' => false,
            ],
        ];
    }
}
