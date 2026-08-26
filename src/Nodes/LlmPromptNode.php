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
use Padosoft\LaravelFlow\Node\PortProvenance;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use Padosoft\LaravelFlowAI\Guardrails\PolicyDeniedException;
use Padosoft\LaravelFlowAI\Llm\LlmRequest;
use RuntimeException;
use stdClass;

/**
 * Templated LLM completion node: renders `{{key}}` placeholders in `$template`
 * from `$variables` (plain string substitution, not Blade — the template is
 * flow-author-supplied content reaching this node as a wired input value, so
 * compiling it as PHP would be an unnecessary code-execution surface for what
 * is, semantically, string interpolation), calls the bound {@see LlmClient},
 * and validates the response decodes to a JSON object. A schema violation
 * (non-JSON, or JSON that isn't an object) retries with the parse error fed
 * back to the model as part of a follow-up prompt, up to `$maxAttempts`.
 *
 * This retry loop is a DATA-validation concern, deliberately separate from
 * core's `#[Retry]` attribute (infra/timeout retries that can drive a node to
 * `dead_letter`): an exhausted schema-retry budget here is a plain `Failed`
 * node, never dead-letter, because no `#[Retry]` policy is ever declared on
 * this handler.
 *
 * Honors `$context->dryRun`: a dry run never calls the LLM (a real network
 * call with real cost is exactly the kind of side effect dry-run exists to
 * skip), returning `NodeResult::dryRunSkipped()` instead.
 *
 * `$variables` pass through the bound {@see PayloadRedactor} (when one is
 * wired — see the constructor) BEFORE template rendering, so a redacted-list
 * key wired into a prompt variable never reaches the external LLM provider.
 * Egress/rate-limit/per-node-type policy is enforced separately, one layer
 * out, by whatever {@see LlmClient} this node was constructed with — a real
 * deployment binds `LlmClient::class` to a `Guardrails\GuardedLlmClient`
 * wrapping the actual provider driver, so this node never has to know policy
 * exists; it just calls `$this->client->complete()`. *
 * Provenance: `$result` is `Untrusted` — a model completion is someone
 * else's words, and an attacker who can influence anything the model read
 * chose them. `$model` and `$systemPrompt` are `requiresTrusted`, which is
 * the less obvious half: a graph that lets model output write the *system
 * prompt* of a later call has handed the model authorship of its own
 * instructions, and one that lets it choose the `$model` has handed it the
 * choice of which provider receives the conversation. `$template` and
 * `$variables` are deliberately left open — feeding a completion into a
 * follow-up prompt is the ordinary summarise-then-refine chain, and it is
 * not where authority leaks.
 *
 * @api
 */
#[FlowNode(
    type: 'ai.llm.prompt',
    category: 'ai',
    description: 'Templated LLM completion with structured JSON output and schema-violation self-repair.',
)]
final class LlmPromptNode implements FlowNodeHandler
{
    private const DEFAULT_MAX_ATTEMPTS = 3;

    #[Input(type: PortType::Text, required: true)]
    public string $template = '';

    #[Input(type: PortType::Text, required: true, requiresTrusted: true)]
    public string $model = '';

    #[Input(type: PortType::Text, required: false, requiresTrusted: true)]
    public string $systemPrompt = '';

    /** @var array<string, mixed> */
    #[Input(type: PortType::Json, required: false)]
    public array $variables = [];

    /** @var array<string, mixed> */
    #[Output(type: PortType::Json, provenance: PortProvenance::Untrusted)]
    public array $result;

    /**
     * `$redactor` is nullable so direct construction in tests (bypassing the
     * container) keeps working without wiring a redactor — but a REAL,
     * container-built instance (the normal path when a graph runs this node)
     * still receives core's bound {@see PayloadRedactor} automatically:
     * Laravel's container resolves a type-hinted class parameter from its
     * bindings BEFORE falling back to a default value, so `null` only takes
     * effect when nothing constructs this class through the container.
     */
    public function __construct(
        private readonly LlmClient $client,
        private readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        private readonly ?PayloadRedactor $redactor = null,
    ) {
        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException("LlmPromptNode maxAttempts must be at least 1, got {$this->maxAttempts}.");
        }
    }

    public function execute(NodeContext $context): NodeResult
    {
        if ($context->dryRun) {
            return NodeResult::dryRunSkipped();
        }

        $template = (string) ($context->inputs['template'] ?? '');
        $model = (string) ($context->inputs['model'] ?? '');
        $systemPrompt = (string) ($context->inputs['systemPrompt'] ?? '');
        /** @var array<string, mixed> $variables */
        $variables = is_array($context->inputs['variables'] ?? null) ? $context->inputs['variables'] : [];

        // Redact BEFORE rendering, not after: a redacted-list key wired into
        // `variables` (a flow author accidentally passing a `password`/
        // `api_key`/etc. input straight into a prompt) must never reach the
        // template substitution step, or its value would already be baked
        // into the outbound prompt string by the time anything downstream
        // could catch it — this guards the EXTERNAL provider call, a
        // distinct concern from core's persistence-redaction gate (which
        // protects what THIS application stores, not what a third-party
        // service receives).
        if ($this->redactor !== null) {
            $variables = $this->redactor->redact($variables);
        }

        try {
            $prompt = $this->render($template, $variables);
        } catch (JsonException $e) {
            // A non-scalar $variables value that isn't JSON-encodable (invalid
            // UTF-8, a resource, etc.) is a malformed INPUT, not a retryable
            // LLM-response schema violation — surface it as a structured node
            // failure like any other execute() error, never an uncaught throw.
            return NodeResult::failed($e);
        }

        $promptTokens = 0;
        $completionTokens = 0;
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $request = new LlmRequest(
                prompt: $lastError === null
                    ? $prompt
                    : $prompt."\n\nYour previous response was invalid: {$lastError}\nRespond again with ONLY a valid JSON object.",
                model: $model,
                systemPrompt: $systemPrompt !== '' ? $systemPrompt : null,
                responseSchema: ['type' => 'object'],
            );

            try {
                $response = $this->client->complete($request);
            } catch (PolicyDeniedException $e) {
                // A policy denial (egress/rate-limit/permission) is an
                // infra/permission concern, not a data-shape one — it must
                // NEVER be retried by this loop (retrying would just burn
                // attempts against a call that denies identically every
                // time, or worse, mask a genuine misconfiguration as a
                // schema-validation failure). Fail the node immediately;
                // core's NodeExecutor would also catch an uncaught
                // Throwable here, but this node handles it explicitly so
                // its own behavior is correct and testable in isolation,
                // not only when driven through the executor.
                return NodeResult::failed($e);
            }

            $promptTokens += $response->promptTokens;
            $completionTokens += $response->completionTokens;

            [$decoded, $lastError] = $this->tryDecodeObject($response->content);

            if ($decoded !== null) {
                return NodeResult::success(
                    ['result' => $decoded],
                    // $response->model, not the requested $model: a provider
                    // may canonicalize/alias the requested model id or route
                    // to a different one, so the REQUESTED name can misattribute
                    // token spend in the reported business impact.
                    $this->businessImpact($response->model, $promptTokens, $completionTokens),
                );
            }
        }

        return NodeResult::failed(new RuntimeException(
            "LLM response failed JSON-object validation after {$this->maxAttempts} attempt(s): {$lastError}",
        ));
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
     * @return array{0: array<string, mixed>|null, 1: string|null} [decoded value on success, specific failure reason on rejection — fed back into the next retry prompt so self-repair has something concrete to act on]
     */
    private function tryDecodeObject(string $content): array
    {
        try {
            // Decoded non-associatively FIRST, purely to check the
            // top-level shape: PHP's associative json_decode() maps BOTH
            // `{}` and `[]` to the same empty PHP array, making the two
            // indistinguishable after the fact — decoding to objects first
            // lets `{}` come back as an empty stdClass (a real JSON object)
            // while `[]` comes back as an empty PHP array (never an
            // object), the only way to reject an empty JSON ARRAY while
            // still accepting a legitimately empty `{}`.
            $shape = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [null, "response was not valid JSON: {$e->getMessage()}"];
        }

        if (! ($shape instanceof stdClass)) {
            return [null, 'response was valid JSON but not an object (got '.get_debug_type($shape).')'];
        }

        // Decoded a SECOND time, associatively, for the actual value: a
        // shallow `(array) $shape` cast does not recurse into nested
        // object members (e.g. a nested "profile": {"name": "Ada"} field),
        // silently leaving them as stdClass instead of array in this
        // node's `result` output port — a bug this exact copy-paste
        // pattern already caused once in BoundedAgentNode (F-PR6) and,
        // caught here on the Macro F gate review, turns out to have been
        // copied FROM this method in the first place. See docs/LESSON.md.
        /** @var array<string, mixed> $value */
        $value = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return [$value, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function businessImpact(string $model, int $promptTokens, int $completionTokens): array
    {
        return [
            'model' => $model,
            'tokens' => [
                'prompt' => $promptTokens,
                'completion' => $completionTokens,
                'total' => $promptTokens + $completionTokens,
            ],
        ];
    }
}
