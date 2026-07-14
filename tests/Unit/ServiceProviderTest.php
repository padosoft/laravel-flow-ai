<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use Padosoft\LaravelFlowAI\Guardrails\GuardedLlmClient;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;

final class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        // Core's provider is required too: LlmPromptNode auto-resolves core's
        // PayloadRedactor via the container, and a real deployment always has
        // both providers registered together (this package requires core).
        return [LaravelFlowServiceProvider::class, LaravelFlowAIServiceProvider::class];
    }

    public function test_provider_is_loaded(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(LaravelFlowAIServiceProvider::class));
    }

    public function test_config_is_merged(): void
    {
        $this->assertSame(
            'https://api.anthropic.com/v1/messages',
            config('laravel-flow-ai.anthropic.base_url'),
        );
    }

    public function test_llm_client_resolves_to_a_guarded_client_by_default(): void
    {
        // F-PR3: the binding shape changed from the raw AnthropicDriver to a
        // GuardedLlmClient wrapping it, so every real consumer gets policy
        // enforcement transparently. See GuardedLlmClientTest for behavior.
        $this->assertInstanceOf(GuardedLlmClient::class, $this->app->make(LlmClient::class));
    }

    public function test_llm_client_is_a_singleton(): void
    {
        $this->assertSame($this->app->make(LlmClient::class), $this->app->make(LlmClient::class));
    }
}
