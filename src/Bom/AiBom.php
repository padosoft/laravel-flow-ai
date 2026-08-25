<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Bom;

use Composer\InstalledVersions;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\QueryException;
use JsonException;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionSignatureException;
use Padosoft\LaravelFlowAI\Console\Commands\AiBomCommand;
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use Padosoft\LaravelFlowAI\Contracts\McpToolAuthorizer;
use Padosoft\LaravelFlowAI\Mcp\Pinning\PinRegistry;
use Padosoft\LaravelFlowAI\Mcp\Pinning\ToolPins;
use Padosoft\LaravelFlowAI\Nodes\BoundedAgentNode;
use Padosoft\LaravelFlowAI\Nodes\LlmPromptNode;
use Padosoft\LaravelFlowAI\Support\CanonicalJson;
use Throwable;

/**
 * The AI bill of materials: one document answering *"what does this
 * application's AI stack consist of, what may it reach, and what bounds
 * it?"* — the question that is asked after an incident and cannot be
 * answered from a `composer.lock`, because half the supply chain is not
 * packages. It is MCP servers spawned by name, tool descriptions fetched
 * from those servers at run time, model endpoints, and the guardrail
 * configuration that decides which of it is reachable.
 *
 * Everything here is derived from CONFIGURATION and the container, never by
 * connecting to anything: generating a BOM must be safe to run in CI, on a
 * machine with no network and no MCP servers installed. The one thing that
 * would require a live server — the digests of the tools it currently
 * advertises — is instead read from the pins, which is the right source
 * anyway: a BOM should record what was APPROVED, and
 * {@see ToolPins} is what enforces that
 * the running server still matches.
 *
 * **No secrets.** API keys are never read. Endpoints appear as configured
 * URLs, which carry no credentials in this package (the Anthropic driver
 * authenticates with a header, deliberately — see the README). A BOM is
 * meant to be committed, diffed and attached to a release; anything in it
 * must be safe in a pull request.
 *
 * {@see digest()} excludes `generatedAt`, so two BOMs of the same
 * application compare equal and CI can fail a build whose AI supply chain
 * changed without anyone saying so — see {@see AiBomCommand}'s `--digest`.
 *
 * @api
 */
final class AiBom
{
    public const FORMAT = 'padosoft-ai-bom';

    public const SPEC_VERSION = '1.0';

    /**
     * Packages whose presence and version materially describe this
     * application's AI surface. A curated list rather than the whole
     * installed set: a BOM that repeats `composer.lock` is a worse
     * `composer.lock`, and the point here is the AI-relevant subset a
     * reviewer would otherwise have to know to look for.
     *
     * @var list<string>
     */
    private const RELEVANT_PACKAGES = [
        'padosoft/laravel-flow-ai',
        'padosoft/laravel-flow',
        'padosoft/laravel-flow-admin',
        'laravel/ai',
        'padosoft/laravel-iam-agents',
        'padosoft/laravel-iam-client',
        'padosoft/laravel-ai-finops',
        'padosoft/laravel-ai-guardrails',
        'padosoft/laravel-ai-act-compliance',
        'padosoft/laravel-pii-redactor',
        'padosoft/eval-harness',
    ];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Container $container,
        private readonly PinRegistry $pins,
        private readonly ?DefinitionRepository $definitions = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(?DateTimeInterface $generatedAt = null): array
    {
        return [
            'bomFormat' => self::FORMAT,
            'specVersion' => self::SPEC_VERSION,
            'generatedAt' => ($generatedAt ?? new DateTimeImmutable)->format(DateTimeInterface::ATOM),
            'packages' => $this->packages(),
            'providers' => $this->providers(),
            'mcpServers' => $this->mcpServers(),
            'exposedFlows' => $this->exposedFlows(),
            'controls' => $this->controls(),
        ];
    }

    /**
     * Stable across regenerations of an unchanged application: the
     * timestamp is excluded, so a difference means the AI supply chain
     * itself moved.
     *
     * @throws JsonException when the configuration carries a value that
     *                       cannot be JSON-encoded
     */
    public function digest(): string
    {
        $document = $this->toArray();
        unset($document['generatedAt']);

        return CanonicalJson::digest($document);
    }

    /**
     * @return list<array{name: string, version: string}>
     */
    private function packages(): array
    {
        if (! class_exists(InstalledVersions::class)) {
            return [];
        }

        $packages = [];

        foreach (self::RELEVANT_PACKAGES as $name) {
            if (! InstalledVersions::isInstalled($name)) {
                continue;
            }

            $packages[] = [
                'name' => $name,
                'version' => InstalledVersions::getPrettyVersion($name) ?? 'unknown',
            ];
        }

        return $packages;
    }

    /**
     * Model PROVIDERS, not models.
     *
     * A model id is a wired input port on {@see LlmPromptNode}
     * and {@see BoundedAgentNode}, chosen per
     * execution — so "which models does this app use" is a question about
     * run history, not about the application, and answering it here with a
     * config value would be a field that is confidently wrong. What IS
     * static is which client implementation each node-type identity
     * resolves to and which endpoint it is pointed at.
     *
     * @return list<array<string, mixed>>
     */
    private function providers(): array
    {
        /** @var array<string, mixed> $anthropic */
        $anthropic = (array) $this->config->get('laravel-flow-ai.anthropic', []);
        $baseUrl = (string) ($anthropic['base_url'] ?? '');

        return [[
            'binding' => LlmClient::class,
            'resolvesTo' => $this->resolvedClass(LlmClient::class),
            'endpoint' => $baseUrl,
            'host' => is_string(parse_url($baseUrl, PHP_URL_HOST)) ? parse_url($baseUrl, PHP_URL_HOST) : null,
            'apiVersion' => (string) ($anthropic['api_version'] ?? ''),
            'timeoutSeconds' => (int) ($anthropic['timeout_seconds'] ?? 0),
            'modelResolution' => 'per-execution (wired input port, recorded in run history)',
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mcpServers(): array
    {
        $servers = [];

        foreach ($this->pins->pinnedServers() as $serverId => $tools) {
            $entries = [];

            foreach ($tools as $tool => $digest) {
                $entries[] = ['name' => $tool, 'digest' => $digest];
            }

            $servers[] = [
                'id' => $serverId,
                'pinned' => true,
                'tools' => $entries,
            ];
        }

        return $servers;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exposedFlows(): array
    {
        /** @var array<string, mixed> $mcp */
        $mcp = (array) $this->config->get('laravel-flow-ai.mcp', []);
        /** @var list<string> $names */
        $names = array_values(array_unique(array_filter((array) ($mcp['exposed_flows'] ?? []), 'is_string')));

        if ($names === [] || $this->definitions === null) {
            return array_map(static fn (string $name): array => ['name' => $name, 'status' => 'unresolved'], $names);
        }

        $flows = [];

        foreach ($names as $name) {
            try {
                $published = $this->definitions->latest($name, 'published');
            } catch (DefinitionSignatureException|QueryException $e) {
                // A signature mismatch or an unreachable database is worth
                // RECORDING, not worth aborting the document: a BOM that
                // refuses to generate because one flow could not be read is
                // a BOM nobody generates. The reason travels with the entry.
                $flows[] = ['name' => $name, 'status' => 'unreadable', 'reason' => $e->getMessage()];

                continue;
            }

            if ($published === null) {
                // Listed as exposable but not currently published, which
                // FlowToolServer treats as invisible — the BOM says so
                // rather than implying a reachable tool.
                $flows[] = ['name' => $name, 'status' => 'not-published'];

                continue;
            }

            $flows[] = [
                'name' => $name,
                'status' => 'published',
                'version' => $published->version,
                'checksum' => $published->checksum,
                'signed' => $published->signature !== null,
                'publishedAt' => $published->publishedAt?->format(DateTimeInterface::ATOM),
            ];
        }

        return $flows;
    }

    /**
     * @return array<string, mixed>
     */
    private function controls(): array
    {
        /** @var array<string, mixed> $guardrails */
        $guardrails = (array) $this->config->get('laravel-flow-ai.guardrails', []);
        /** @var array<string, mixed> $agent */
        $agent = (array) $this->config->get('laravel-flow-ai.agent', []);

        return [
            'guardrails' => [
                'allowedNodeTypes' => array_values(array_filter((array) ($guardrails['allowed_node_types'] ?? []), 'is_string')),
                'egressAllowlist' => array_values(array_filter((array) ($guardrails['egress_allowlist'] ?? []), 'is_string')),
                'rateLimitMaxAttempts' => (int) ($guardrails['rate_limit_max_attempts'] ?? 0),
                'rateLimitDecaySeconds' => (int) ($guardrails['rate_limit_decay_seconds'] ?? 0),
            ],
            'agent' => [
                'allowedTools' => array_values(array_filter((array) ($agent['allowed_tools'] ?? []), 'is_string')),
                'maxIterations' => (int) ($agent['max_iterations'] ?? 0),
                'maxTotalTokens' => (int) ($agent['max_total_tokens'] ?? 0),
                'maxCostUsd' => is_numeric($agent['max_cost_usd'] ?? null) ? (float) $agent['max_cost_usd'] : null,
                'costPerThousandTokens' => is_numeric($agent['cost_per_thousand_tokens'] ?? null) ? (float) $agent['cost_per_thousand_tokens'] : null,
            ],
            'mcpToolPinning' => [
                'mode' => $this->pins->mode(),
                'requirePins' => $this->pins->requiresPins(),
                'pinnedServers' => count($this->pins->pinnedServers()),
            ],
            'authorizers' => [
                McpToolAuthorizer::class => $this->resolvedClass(McpToolAuthorizer::class),
            ],
        ];
    }

    /**
     * The class the container actually hands back for an abstract — which
     * is the fact that matters (a host may have rebound `McpToolAuthorizer`
     * to something permissive, and a BOM that reported the DEFAULT would be
     * reassuring and false).
     *
     * Resolution runs constructors, and a host's own binding may throw for
     * reasons that have nothing to do with the BOM; that is recorded rather
     * than propagated, for the same reason as `exposedFlows()` above.
     *
     * @param  class-string  $abstract
     */
    private function resolvedClass(string $abstract): string
    {
        try {
            return $this->container->make($abstract)::class;
        } catch (Throwable $e) {
            return 'unresolved: '.$e->getMessage();
        }
    }
}
