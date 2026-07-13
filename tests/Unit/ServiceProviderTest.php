<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;
use Padosoft\LaravelFlowAI\Llm\AnthropicDriver;

final class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowAIServiceProvider::class];
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

    public function test_llm_client_resolves_to_the_anthropic_driver_by_default(): void
    {
        $this->assertInstanceOf(AnthropicDriver::class, $this->app->make(LlmClient::class));
    }

    public function test_llm_client_is_a_singleton(): void
    {
        $this->assertSame($this->app->make(LlmClient::class), $this->app->make(LlmClient::class));
    }
}
