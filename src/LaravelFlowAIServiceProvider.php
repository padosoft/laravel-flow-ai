<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Dashboard\FlowDashboardReadModel;
use Padosoft\LaravelFlowAI\Advisor\Analyzers\DurationOutlierAnalyzer;
use Padosoft\LaravelFlowAI\Advisor\Analyzers\FailureHotspotAnalyzer;
use Padosoft\LaravelFlowAI\Advisor\Analyzers\RepeatedSegmentAnalyzer;
use Padosoft\LaravelFlowAI\Advisor\Analyzers\UnusedToolAnalyzer;
use Padosoft\LaravelFlowAI\Advisor\FlowAdvisor;
use Padosoft\LaravelFlowAI\Builder\FlowBuilderService;
use Padosoft\LaravelFlowAI\Console\Commands\ImproveFlowCommand;
use Padosoft\LaravelFlowAI\Console\Commands\SuggestFlowsCommand;
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use Padosoft\LaravelFlowAI\Contracts\McpToolAuthorizer;
use Padosoft\LaravelFlowAI\Guardrails\GuardedLlmClient;
use Padosoft\LaravelFlowAI\Guardrails\PolicyEngine;
use Padosoft\LaravelFlowAI\Llm\AnthropicDriver;
use Padosoft\LaravelFlowAI\Mcp\Authorization\DenyAllMcpToolAuthorizer;
use Padosoft\LaravelFlowAI\Mcp\FlowToolServer;
use Padosoft\LaravelFlowAI\Mcp\Transport\McpTransportFactory;
use Padosoft\LaravelFlowAI\Mcp\Transport\StdioMcpTransportFactory;
use Padosoft\LaravelFlowAI\Nodes\BoundedAgentNode;
use Padosoft\LaravelFlowAI\Nodes\LlmPromptNode;
use Padosoft\LaravelFlowAI\Nodes\McpClientNode;

/**
 * @internal
 */
final class LaravelFlowAIServiceProvider extends ServiceProvider
{
    /**
     * Node handler classes this package contributes to core's registry.
     *
     * @var list<class-string>
     */
    private const NODE_HANDLERS = [
        LlmPromptNode::class,
        McpClientNode::class,
        BoundedAgentNode::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/laravel-flow-ai.php',
            'laravel-flow-ai',
        );

        // Bound to the GUARDED client, not the raw driver, so the default
        // consumer (LlmPromptNode, resolved through the container) gets
        // policy enforcement transparently. Node type 'ai.llm.prompt' is
        // hardcoded here because THIS binding is that node's identity
        // specifically — a DIFFERENT node type needing its own guarded LLM
        // identity (see BoundedAgentNode's contextual binding below) gets
        // its OWN GuardedLlmClient instance, never this shared singleton:
        // sharing one node-type identity across two node types would let
        // one node's calls authorize/rate-limit under the WRONG node type
        // entirely (a host restricting `ai.llm.prompt` via
        // `guardrails.allowed_node_types` would not actually restrict the
        // OTHER node's calls, and a rate limit meant for one would be
        // silently consumed by the other).
        $this->app->singleton(LlmClient::class, fn (Container $app): LlmClient => $this->guardedLlmClient($app, 'ai.llm.prompt'));

        // BoundedAgentNode's own LLM calls must authorize/rate-limit under
        // ITS OWN node type, not 'ai.llm.prompt' — see the comment above.
        $this->app->when(BoundedAgentNode::class)
            ->needs(LlmClient::class)
            ->give(fn (Container $app): LlmClient => $this->guardedLlmClient($app, 'ai.agent.bounded'));

        // Same reasoning again for FlowBuilderService — a THIRD identity,
        // not a node type at all (this service is invoked directly by
        // application code, never resolved as a graph node), but the same
        // guardrail-misattribution risk applies to ANY class sharing the
        // 'ai.llm.prompt' singleton.
        $this->app->when(FlowBuilderService::class)
            ->needs(LlmClient::class)
            ->give(fn (Container $app): LlmClient => $this->guardedLlmClient($app, 'ai.flow.builder'));

        // ONE shared PolicyEngine singleton across every AI-pack node making
        // an outbound call (currently LlmPromptNode via GuardedLlmClient
        // above, and McpClientNode resolving this binding directly for its
        // own PolicyEngine::class parameter) — not a separate instance per
        // node type. Each gate's cache/allowlist checks are already keyed by
        // node type internally, so sharing costs nothing and keeps one
        // config surface (`laravel-flow-ai.guardrails`) governing every
        // outbound call this package makes, LLM or MCP.
        $this->app->singleton(PolicyEngine::class, fn (Container $app): PolicyEngine => $this->policyEngine($app));

        $this->app->bind(McpTransportFactory::class, function (Container $app): McpTransportFactory {
            /** @var array<string, mixed> $mcpConfig */
            $mcpConfig = (array) $app->make(ConfigRepository::class)->get('laravel-flow-ai.mcp', []);

            return new StdioMcpTransportFactory(
                timeoutSeconds: is_numeric($mcpConfig['timeout_seconds'] ?? null) && (int) $mcpConfig['timeout_seconds'] >= 1
                    ? (int) $mcpConfig['timeout_seconds']
                    : 10,
            );
        });

        // Deny-by-default, same non-negotiable posture as core's
        // DashboardActionAuthorizer -> DenyAllAuthorizer: a fresh install
        // must never expose a flow as an MCP tool without an explicit host
        // application policy.
        $this->app->bind(McpToolAuthorizer::class, DenyAllMcpToolAuthorizer::class);

        $this->app->bind(FlowToolServer::class, function (Container $app): FlowToolServer {
            /** @var array<string, mixed> $mcpConfig */
            $mcpConfig = (array) $app->make(ConfigRepository::class)->get('laravel-flow-ai.mcp', []);

            return new FlowToolServer(
                definitions: $app->make(DefinitionRepository::class),
                runs: $app->make(RunRepository::class),
                authorizer: $app->make(McpToolAuthorizer::class),
                exposedFlowNames: array_values(array_filter((array) ($mcpConfig['exposed_flows'] ?? []), 'is_string')),
            );
        });

        // BoundedAgentNode's scalar/array budget+allowlist params have no
        // class type for the container to auto-resolve — a contextual
        // binding is the only way a container-built instance (the normal
        // path when a graph runs this node) picks up host config instead of
        // silently falling back to the constructor's bare defaults.
        $this->app->when(BoundedAgentNode::class)
            ->needs('$allowedTools')
            ->give(fn (Container $app): array => array_values(array_filter(
                (array) $app->make(ConfigRepository::class)->get('laravel-flow-ai.agent.allowed_tools', []),
                'is_string',
            )));

        $this->app->when(BoundedAgentNode::class)
            ->needs('$maxIterations')
            ->give(fn (Container $app): int => (int) $app->make(ConfigRepository::class)->get('laravel-flow-ai.agent.max_iterations', 5));

        $this->app->when(BoundedAgentNode::class)
            ->needs('$maxTotalTokens')
            ->give(fn (Container $app): int => (int) $app->make(ConfigRepository::class)->get('laravel-flow-ai.agent.max_total_tokens', 4000));

        $this->app->when(BoundedAgentNode::class)
            ->needs('$maxCostUsd')
            ->give(function (Container $app): ?float {
                $value = $app->make(ConfigRepository::class)->get('laravel-flow-ai.agent.max_cost_usd');

                return is_numeric($value) ? (float) $value : null;
            });

        $this->app->when(BoundedAgentNode::class)
            ->needs('$costPerThousandTokens')
            ->give(function (Container $app): ?float {
                $value = $app->make(ConfigRepository::class)->get('laravel-flow-ai.agent.cost_per_thousand_tokens');

                return is_numeric($value) ? (float) $value : null;
            });

        $this->app->bind(FlowAdvisor::class, function (Container $app): FlowAdvisor {
            /** @var array<string, mixed> $config */
            $config = (array) $app->make(ConfigRepository::class)->get('laravel-flow-ai.advisor', []);
            /** @var array<string, mixed> $mcpConfig */
            $mcpConfig = (array) $app->make(ConfigRepository::class)->get('laravel-flow-ai.mcp', []);
            $exposedFlowNames = array_values(array_filter((array) ($mcpConfig['exposed_flows'] ?? []), 'is_string'));

            $redactor = null;

            try {
                $redactor = $app->make(PayloadRedactor::class);
            } catch (BindingResolutionException) {
                // Same "optional dependency, narrowly caught" posture as
                // policyEngine()'s cache lookup above — a harness that never
                // bound this makes redaction a no-op, not a hard failure.
            }

            return new FlowAdvisor(
                readModel: $app->make(FlowDashboardReadModel::class),
                definitions: $app->make(DefinitionRepository::class),
                analyzers: [
                    new FailureHotspotAnalyzer(
                        minFailureRate: is_numeric($config['min_failure_rate'] ?? null) ? (float) $config['min_failure_rate'] : 0.3,
                        minSamples: is_numeric($config['min_samples'] ?? null) ? (int) $config['min_samples'] : 3,
                    ),
                    new DurationOutlierAnalyzer(
                        stdDeviations: is_numeric($config['duration_std_deviations'] ?? null) ? (float) $config['duration_std_deviations'] : 2.0,
                        minSamples: is_numeric($config['min_samples'] ?? null) ? (int) $config['min_samples'] : 5,
                    ),
                    new RepeatedSegmentAnalyzer(
                        minRuns: is_numeric($config['repeated_segment_min_runs'] ?? null) ? (int) $config['repeated_segment_min_runs'] : 3,
                    ),
                    new UnusedToolAnalyzer($exposedFlowNames),
                ],
                exposedFlowNames: $exposedFlowNames,
                redactor: $redactor,
                sampleSize: is_numeric($config['sample_size'] ?? null) ? (int) $config['sample_size'] : 50,
            );
        });
    }

    /**
     * Builds a fresh `AnthropicDriver` wrapped in a `GuardedLlmClient`
     * scoped to `$nodeType` — one instance per CALLING node type, never
     * shared, so each node's calls authorize/rate-limit under its own
     * identity (see the two callers' comments in `register()`).
     */
    private function guardedLlmClient(Container $app, string $nodeType): LlmClient
    {
        /** @var array<string, mixed> $anthropicConfig */
        $anthropicConfig = (array) $app->make(ConfigRepository::class)->get('laravel-flow-ai.anthropic', []);
        $baseUrl = (string) ($anthropicConfig['base_url'] ?? 'https://api.anthropic.com/v1/messages');

        $driver = new AnthropicDriver(
            apiKey: (string) ($anthropicConfig['api_key'] ?? ''),
            baseUrl: $baseUrl,
            apiVersion: (string) ($anthropicConfig['api_version'] ?? '2023-06-01'),
            timeoutSeconds: is_numeric($anthropicConfig['timeout_seconds'] ?? null) && (int) $anthropicConfig['timeout_seconds'] >= 1
                ? (int) $anthropicConfig['timeout_seconds']
                : 30,
        );

        return new GuardedLlmClient(
            inner: $driver,
            policy: $app->make(PolicyEngine::class),
            nodeType: $nodeType,
            targetHost: $this->requireHost($baseUrl),
        );
    }

    /**
     * Fails fast at container-resolution time on a malformed `base_url`,
     * rather than silently deriving an empty target host that would produce
     * confusing egress-allowlist denials (or, worse, an ALWAYS-EMPTY host
     * that an allowlist entry could accidentally match) later, at CALL time,
     * far from the actual misconfiguration.
     */
    private function requireHost(string $baseUrl): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException(
                "laravel-flow-ai.anthropic.base_url [{$baseUrl}] has no parseable host — expected an absolute URL such as https://api.anthropic.com/v1/messages."
            );
        }

        return $host;
    }

    private function policyEngine(Container $app): PolicyEngine
    {
        /** @var array<string, mixed> $config */
        $config = (array) $app->make(ConfigRepository::class)->get('laravel-flow-ai.guardrails', []);

        // The cache repository is a core Laravel service, always bound in a
        // real application; this narrowly catches ONLY "the binding isn't
        // registered" (a stripped-down test harness that never bound it, not
        // an expected production path — falling back to null simply makes
        // the rate-limit gate a no-op, this package's established
        // "permissive when unconfigured" posture). A DIFFERENT exception
        // (e.g. the bound cache driver itself failing to construct — a real
        // misconfiguration) must surface, not be silently swallowed into a
        // disabled rate limit.
        try {
            $cache = $app->make(CacheRepository::class);
        } catch (BindingResolutionException) {
            $cache = null;
        }

        return new PolicyEngine(
            allowedNodeTypes: array_values(array_filter((array) ($config['allowed_node_types'] ?? []), 'is_string')),
            egressAllowlist: array_values(array_filter((array) ($config['egress_allowlist'] ?? []), 'is_string')),
            rateLimitMaxAttempts: is_numeric($config['rate_limit_max_attempts'] ?? null) ? (int) $config['rate_limit_max_attempts'] : 0,
            rateLimitDecaySeconds: is_numeric($config['rate_limit_decay_seconds'] ?? null) && (int) $config['rate_limit_decay_seconds'] >= 1
                ? (int) $config['rate_limit_decay_seconds']
                : 60,
            cache: $cache,
        );
    }

    /**
     * Append this package's node handlers to core's `laravel-flow.nodes.handlers`
     * config so `NodeRegistry` (a lazily-resolved singleton in core's own
     * provider) picks them up. Done in `boot()`, NOT `register()`: core's own
     * `mergeConfigFrom('laravel-flow')` runs during core's `register()`, and
     * Laravel's `mergeConfigFrom()` is a SHALLOW, top-level `array_merge()` —
     * if this package instead wrote to `laravel-flow.nodes.*` during ITS OWN
     * `register()` and happened to run BEFORE core's provider (package
     * registration order is not guaranteed), core's later merge would treat
     * the whole `nodes` key as already-set and skip merging its own defaults
     * under it, silently dropping sibling keys such as `nodes.discovery`.
     * Laravel guarantees EVERY provider's `register()` completes before ANY
     * provider's `boot()` starts, so deferring to `boot()` guarantees core's
     * config is fully merged first, regardless of provider list order.
     */
    private function registerNodeHandlers(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $existing = (array) $config->get('laravel-flow.nodes.handlers', []);

        $config->set(
            'laravel-flow.nodes.handlers',
            array_values(array_unique([...$existing, ...self::NODE_HANDLERS])),
        );
    }

    public function boot(): void
    {
        $this->registerNodeHandlers();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-flow-ai.php' => $this->app->configPath('laravel-flow-ai.php'),
        ], 'laravel-flow-ai-config');

        $this->commands([
            SuggestFlowsCommand::class,
            ImproveFlowCommand::class,
        ]);
    }
}
