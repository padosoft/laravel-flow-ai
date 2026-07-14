<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Builder;

use InvalidArgumentException;
use JsonException;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Graph\GraphValidator;
use Padosoft\LaravelFlow\Node\NodeDefinition;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use Padosoft\LaravelFlowAI\Guardrails\PolicyDeniedException;
use Padosoft\LaravelFlowAI\Llm\LlmRequest;
use stdClass;

/**
 * Turns a natural-language prompt into a VALIDATED {@see GraphDefinition}
 * draft — never an invalid one. The model is prompted for a structured
 * `{nodes, connections}` description (this package's node CATALOG, from
 * core's {@see NodeRegistry}, is described in the prompt so it picks real
 * types rather than hallucinating them), mapped onto
 * `GraphNode`/`Connection`/`GraphDefinition`, and run through core's
 * EXISTING `GraphValidator` before ever being returned — a malformed
 * response, a structurally broken graph (dangling connection, cycle,
 * duplicate id), or a semantically invalid one (unknown node type, a wire
 * onto the wrong port type) all become a typed {@see FlowBuilderResult::failed()}
 * with the concrete violation list, never a silently-broken draft a caller
 * could persist by mistake.
 *
 * Deliberately SINGLE-SHOT, no internal self-repair loop (unlike
 * `LlmPromptNode`/`BoundedAgentNode`): the master plan's own wording is "a
 * validation failure becomes a typed result the CALLER can inspect/retry
 * from" — retrying belongs to whatever consumes this result (a future
 * `flow:suggest` command, a Studio panel), not this service auto-looping
 * against its own failures.
 *
 * @api
 */
final class FlowBuilderService
{
    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'nodes' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'type' => ['type' => 'string'],
                        'config' => ['type' => 'object'],
                    ],
                    'required' => ['id', 'type'],
                ],
            ],
            'connections' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'from_node' => ['type' => 'string'],
                        'from_port' => ['type' => 'string'],
                        'to_node' => ['type' => 'string'],
                        'to_port' => ['type' => 'string'],
                    ],
                    'required' => ['from_node', 'from_port', 'to_node', 'to_port'],
                ],
            ],
        ],
        'required' => ['nodes', 'connections'],
    ];

    public function __construct(
        private readonly LlmClient $client,
        private readonly NodeRegistry $registry,
        private readonly GraphValidator $validator,
    ) {}

    public function build(string $prompt, string $model): FlowBuilderResult
    {
        $request = new LlmRequest(
            prompt: $this->renderPrompt($prompt),
            model: $model,
            responseSchema: self::RESPONSE_SCHEMA,
        );

        try {
            $response = $this->client->complete($request);
        } catch (PolicyDeniedException $e) {
            return FlowBuilderResult::failed([$e->getMessage()]);
        }

        [$decoded, $error] = $this->tryDecodeObject($response->content);

        if ($decoded === null) {
            /** @var string $error */
            return FlowBuilderResult::failed([$error]);
        }

        try {
            $graph = $this->mapToGraphDefinition($decoded);
            $this->validator->validate($graph);
        } catch (InvalidArgumentException $e) {
            return FlowBuilderResult::failed(
                $e instanceof InvalidGraphException ? $e->violations() : [$e->getMessage()],
            );
        }

        return FlowBuilderResult::success($graph);
    }

    /**
     * @param  array<string, mixed>  $decoded
     *
     * @throws InvalidArgumentException when a node/connection field is malformed
     * @throws InvalidGraphException when the assembled graph is structurally invalid
     */
    private function mapToGraphDefinition(array $decoded): GraphDefinition
    {
        /** @var list<mixed> $nodesData */
        $nodesData = is_array($decoded['nodes'] ?? null) ? array_values($decoded['nodes']) : [];
        /** @var list<mixed> $connectionsData */
        $connectionsData = is_array($decoded['connections'] ?? null) ? array_values($decoded['connections']) : [];

        $nodes = [];

        foreach ($nodesData as $nodeData) {
            // array_is_list() rejects a JSON ARRAY entry (e.g. "nodes":[[1,2]]),
            // which decodes to the same PHP array shape is_array() alone
            // accepts — a non-empty JSON array is never a valid node object
            // per the requested schema.
            if (! is_array($nodeData) || array_is_list($nodeData)) {
                throw new InvalidArgumentException('Each "nodes" entry must be an object.');
            }

            // Explicit is_string() checks, not a (string) cast: PHP coerces
            // an array value to the literal string "Array" (with a warning,
            // not an error) rather than failing — a malformed "id"/"type"
            // field (e.g. the model returning an array where a string was
            // requested) would otherwise silently build a GARBAGE but
            // seemingly-valid node instead of a typed failure.
            if (! is_string($nodeData['id'] ?? null) || ! is_string($nodeData['type'] ?? null)) {
                throw new InvalidArgumentException('Each "nodes" entry must have string "id" and "type" fields.');
            }

            /** @var array<string, mixed> $config */
            $config = is_array($nodeData['config'] ?? null) ? $nodeData['config'] : [];

            $nodes[] = new GraphNode(
                id: $nodeData['id'],
                type: $nodeData['type'],
                config: $config,
            );
        }

        $connections = [];

        foreach ($connectionsData as $connectionData) {
            // Same rationale as the nodes loop above.
            if (! is_array($connectionData) || array_is_list($connectionData)) {
                throw new InvalidArgumentException('Each "connections" entry must be an object.');
            }

            foreach (['from_node', 'from_port', 'to_node', 'to_port'] as $field) {
                if (! is_string($connectionData[$field] ?? null)) {
                    throw new InvalidArgumentException("Each \"connections\" entry must have a string \"{$field}\" field.");
                }
            }

            $connections[] = new Connection(
                sourceNodeId: $connectionData['from_node'],
                sourcePortKey: $connectionData['from_port'],
                targetNodeId: $connectionData['to_node'],
                targetPortKey: $connectionData['to_port'],
            );
        }

        return new GraphDefinition($nodes, $connections);
    }

    private function renderPrompt(string $prompt): string
    {
        $catalog = array_map(
            static fn (NodeDefinition $definition): array => $definition->toArray(),
            $this->registry->all(),
        );

        try {
            $catalogJson = json_encode(array_values($catalog), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $catalogJson = '[]';
        }

        return implode("\n", [
            'You are designing a Laravel Flow graph from a natural-language request.',
            'Request:',
            $prompt,
            '',
            'Available node types (pick ONLY from this catalog, by exact "type" value):',
            $catalogJson,
            '',
            'Respond with ONLY a JSON object of this shape:',
            '{"nodes":[{"id":"<unique id>","type":"<a type from the catalog>","config":{...}}],'
            .'"connections":[{"from_node":"<id>","from_port":"<output port key>","to_node":"<id>","to_port":"<input port key>"}]}',
        ]);
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function tryDecodeObject(string $content): array
    {
        try {
            // Decode non-associatively FIRST purely to check the top-level
            // shape ({} vs [] both decode to the same empty PHP array under
            // json_decode(..., true) — see docs/LESSON.md), THEN decode a
            // second time associatively for the real, fully-recursive value
            // (a shallow (array) cast on the first decode would leave nested
            // objects like a node's "config" as stdClass instead of array).
            $shape = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [null, "response was not valid JSON: {$e->getMessage()}"];
        }

        if (! ($shape instanceof stdClass)) {
            return [null, 'response was valid JSON but not an object (got '.get_debug_type($shape).')'];
        }

        /** @var array<string, mixed> $value */
        $value = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return [$value, null];
    }
}
