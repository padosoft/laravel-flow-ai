<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Advisor;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlowAI\Advisor\FlowAdvisor;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;

final class FlowAdvisorRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowAIServiceProvider::class];
    }

    public function test_the_service_is_container_resolvable(): void
    {
        $advisor = $this->app->make(FlowAdvisor::class);

        $this->assertInstanceOf(FlowAdvisor::class, $advisor);
    }

    public function test_the_commands_are_registered(): void
    {
        $names = array_keys(Artisan::all());

        $this->assertContains('flow:suggest', $names);
        $this->assertContains('flow:improve', $names);
    }
}
