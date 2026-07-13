<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use Padosoft\LaravelFlowAI\Llm\AnthropicDriver;
use Padosoft\LaravelFlowAI\Nodes\LlmPromptNode;

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
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/laravel-flow-ai.php',
            'laravel-flow-ai',
        );

        $this->app->singleton(LlmClient::class, function (Container $app): LlmClient {
            /** @var array<string, mixed> $config */
            $config = (array) $app->make(ConfigRepository::class)->get('laravel-flow-ai.anthropic', []);

            return new AnthropicDriver(
                apiKey: (string) ($config['api_key'] ?? ''),
                baseUrl: (string) ($config['base_url'] ?? 'https://api.anthropic.com/v1/messages'),
                apiVersion: (string) ($config['api_version'] ?? '2023-06-01'),
                timeoutSeconds: is_numeric($config['timeout_seconds'] ?? null) && (int) $config['timeout_seconds'] >= 1
                    ? (int) $config['timeout_seconds']
                    : 30,
            );
        });
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
    }
}
