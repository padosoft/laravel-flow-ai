<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Nodes;

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
    ) {}

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

        $prompt = $this->render($template, $variables);

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

            $decoded = $this->tryDecodeObject($response->content);

            if ($decoded !== null) {
                return NodeResult::success(
                    ['result' => $decoded],
                    $this->businessImpact($model, $promptTokens, $completionTokens),
                );
            }

            $lastError = 'response was not a valid JSON object';
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
     * @return array<string, mixed>|null
     */
    private function tryDecodeObject(string $content): ?array
    {
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        // A JSON array (list) decodes to a PHP list, which is NOT a JSON
        // object — the node promises object-shaped structured output. An
        // EMPTY array is ambiguous (both `{}` and `[]` decode to `[]`), so it
        // is accepted: array_is_list([]) is vacuously true and would
        // otherwise reject a legitimately empty `{}` response.
        return $decoded === [] || ! array_is_list($decoded) ? $decoded : null;
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
