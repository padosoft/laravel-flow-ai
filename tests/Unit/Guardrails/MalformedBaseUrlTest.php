<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Guardrails;

use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;

/**
 * Round-1 review (Copilot): a `base_url` that `parse_url()` can't derive a
 * host from must fail LOUDLY at container-resolution time, not silently
 * produce an empty target host that would cause confusing egress-allowlist
 * behavior far later, at call time.
 */
final class MalformedBaseUrlTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowAIServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('laravel-flow-ai.anthropic.base_url', 'not-a-url-at-all');
    }

    public function test_resolving_llm_client_throws_a_clear_configuration_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/base_url.*no parseable host/');

        $this->app->make(LlmClient::class);
    }
}
