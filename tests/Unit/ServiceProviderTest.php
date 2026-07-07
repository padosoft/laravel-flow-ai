<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;

final class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowAIServiceProvider::class];
    }

    public function testProviderIsLoaded(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(LaravelFlowAIServiceProvider::class));
    }
}
