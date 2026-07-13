<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use Padosoft\LaravelFlowAI\Llm\AnthropicDriver;

/**
 * @internal
 */
final class LaravelFlowAIServiceProvider extends ServiceProvider
{
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

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-flow-ai.php' => $this->app->configPath('laravel-flow-ai.php'),
        ], 'laravel-flow-ai-config');
    }
}
