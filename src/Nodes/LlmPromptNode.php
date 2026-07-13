<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Nodes;

use InvalidArgumentException;
use JsonException;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
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

    #[Input(type: PortType::Text, required: true)]
    public string $model = '';

    #[Input(type: PortType::Text, required: false)]
    public string $systemPrompt = '';

    /** @var array<string, mixed> */
    #[Input(type: PortType::Json, required: false)]
    public array $variables = [];

    /** @var array<string, mixed> */
    #[Output(type: PortType::Json)]
    public array $result;

    public function __construct(
        private readonly LlmClient $client,
        private readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
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

            $response = $this->client->complete($request);
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
            // Decoded WITHOUT the associative flag on purpose: PHP's
            // associative json_decode() maps BOTH `{}` and `[]` to the same
            // empty PHP array, making the two indistinguishable after the
            // fact. Decoding to objects first lets `{}` come back as an empty
            // stdClass (a real JSON object) while `[]` comes back as an empty
            // PHP array (never an object) — the only way to reject an empty
            // JSON ARRAY while still accepting a legitimately empty `{}`.
            $decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [null, "response was not valid JSON: {$e->getMessage()}"];
        }

        if (! ($decoded instanceof stdClass)) {
            return [null, 'response was valid JSON but not an object (got '.get_debug_type($decoded).')'];
        }

        /** @var array<string, mixed> $value */
        $value = (array) $decoded;

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
