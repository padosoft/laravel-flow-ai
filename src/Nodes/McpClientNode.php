<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Nodes;

use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortProvenance;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlowAI\Guardrails\PolicyDeniedException;
use Padosoft\LaravelFlowAI\Guardrails\PolicyEngine;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpConnectionException;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpToolExecutionException;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpToolPinMismatchException;
use Padosoft\LaravelFlowAI\Mcp\McpClient;
use Padosoft\LaravelFlowAI\Mcp\Pinning\PinRegistry;
use Padosoft\LaravelFlowAI\Mcp\Transport\McpTransportFactory;

/**
 * Connects to an MCP server over stdio (spawns `$command $args...` as a
 * child process), calls one tool, and maps its result onto the `result`
 * output port. `$command`/`$args`/`$tool`/`$arguments` are all wired INPUT
 * ports (not constructor config, unlike {@see LlmPromptNode}'s fixed
 * provider): a graph author can point this node at a different MCP server
 * per execution, so a fresh {@see McpTransportFactory}-built transport (and,
 * for stdio, a fresh subprocess) is created per `execute()` call — never
 * reused across executions.
 *
 * Guardrails: spawning an arbitrary local command is a LARGER security
 * surface than an HTTP call (arbitrary code execution, not just an outbound
 * request), so `$policy` (when wired) authorizes every call under a
 * synthetic pseudo-host `"stdio:{$command}"` — a deliberate, documented
 * reuse of {@see PolicyEngine}'s existing egress-allowlist mechanism: a
 * host app that wants to restrict which commands this node may spawn
 * configures `egress_allowlist` with `stdio:npx`, `stdio:php`, etc., the
 * exact same config surface already used for LLM provider hosts. `$tool`
 * call ARGUMENTS pass through the bound {@see PayloadRedactor} (when wired)
 * before the call — the same "redact before it leaves the process" posture
 * {@see LlmPromptNode} applies to prompt variables — since MCP tool
 * arguments are exactly as likely to carry a wired-in secret as an LLM
 * prompt variable is.
 *
 * A connection-level failure ({@see McpConnectionException} — the call
 * never completed) and a tool-reported failure ({@see McpToolExecutionException}
 * — the call completed, the tool itself said no) both map to
 * `NodeResult::failed()`, but as DISTINGUISHABLE exception types, per this
 * subtask's own gate criterion. A pin mismatch
 * ({@see McpToolPinMismatchException} — the call was blocked because the
 * server no longer offers the contracts that were approved) is the third,
 * and maps the same way: a failed run an operator can see, not a silent
 * exception escaping the node.
 *
 * Provenance: `$command`, `$args` and `$tool` are `requiresTrusted` — they
 * decide WHICH process is spawned and WHICH operation runs, so a graph that
 * lets model output reach them is rejected at publish time rather than
 * discovered afterwards. `$arguments` deliberately is NOT: filling in the
 * parameters of a tool the graph author chose is the whole point of tool
 * use, and forbidding it would only teach people to turn the check off.
 * The line is: **the model may fill in parameters, never select the
 * operation or the executable.** That is why pinning ({@see Pinning\ToolPins})
 * matters — with the tool fixed by the author, the pin is what stops the
 * server from quietly redefining what that tool does.
 *
 * `$result` is `Untrusted`: an MCP tool's response is a remote server's
 * words, exactly as much someone else's text as a model completion is.
 *
 * Honors `$context->dryRun`: a dry run never spawns a process, returning
 * `NodeResult::dryRunSkipped()` instead.
 *
 * @api
 */
#[FlowNode(
    type: 'ai.mcp.tool',
    category: 'ai',
    description: 'Calls a tool on an MCP server over stdio.',
)]
final class McpClientNode implements FlowNodeHandler
{
    #[Input(type: PortType::Text, required: true, requiresTrusted: true)]
    public string $command = '';

    /** @var list<string> */
    #[Input(type: PortType::Json, required: false, requiresTrusted: true)]
    public array $args = [];

    #[Input(type: PortType::Text, required: true, requiresTrusted: true)]
    public string $tool = '';

    /** @var array<string, mixed> */
    #[Input(type: PortType::Json, required: false)]
    public array $arguments = [];

    /** @var list<mixed> */
    #[Output(type: PortType::Json, provenance: PortProvenance::Untrusted)]
    public array $result;

    /**
     * @param  PinRegistry|null  $pins  when bound (the service provider always binds it; null keeps this node constructible by hand), the server's advertised tool contracts are verified against their pins before the call — a no-op unless `mcp.pinning.mode` is on
     */
    public function __construct(
        private readonly McpTransportFactory $transportFactory,
        private readonly ?PolicyEngine $policy = null,
        private readonly ?PayloadRedactor $redactor = null,
        private readonly ?PinRegistry $pins = null,
    ) {}

    public function execute(NodeContext $context): NodeResult
    {
        if ($context->dryRun) {
            return NodeResult::dryRunSkipped();
        }

        $command = (string) ($context->inputs['command'] ?? '');
        /** @var list<string> $args */
        $args = array_values(array_filter((array) ($context->inputs['args'] ?? []), 'is_string'));
        $tool = (string) ($context->inputs['tool'] ?? '');
        /** @var array<string, mixed> $arguments */
        $arguments = is_array($context->inputs['arguments'] ?? null) ? $context->inputs['arguments'] : [];

        if ($this->redactor !== null) {
            $arguments = $this->redactor->redact($arguments);
        }

        if ($this->policy !== null) {
            $decision = $this->policy->authorize('ai.mcp.tool', "stdio:{$command}");

            if (! $decision->allowed) {
                return NodeResult::failed(new PolicyDeniedException((string) $decision->reason));
            }
        }

        $client = new McpClient(
            $this->transportFactory->stdio($command, $args),
            pins: $this->pins?->forServer($command, $args),
        );

        try {
            $content = $client->callTool($tool, $arguments);
        } catch (McpConnectionException|McpToolExecutionException|McpToolPinMismatchException $e) {
            return NodeResult::failed($e);
        } finally {
            $client->close();
        }

        return NodeResult::success(['result' => $content]);
    }
}
