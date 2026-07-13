<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Guardrails;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;
use RuntimeException;

/**
 * Round-2 review (Copilot): the cache-repository resolution catch must be
 * narrow (only "binding not registered", the expected gap in a stripped
 * test harness) — a genuinely BROKEN cache driver must surface, not be
 * silently swallowed into a disabled rate limit.
 */
final class CacheResolutionFailureTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowAIServiceProvider::class];
    }

    public function test_a_genuinely_broken_cache_binding_surfaces_instead_of_being_swallowed(): void
    {
        $this->app->bind(CacheRepository::class, function (): never {
            throw new RuntimeException('cache driver is genuinely broken');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cache driver is genuinely broken');

        $this->app->make(LlmClient::class);
    }
}
