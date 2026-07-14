<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Builder;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlowAI\Builder\FlowBuilderService;
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;
use ReflectionClass;

final class FlowBuilderServiceRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowAIServiceProvider::class];
    }

    public function test_the_service_is_container_resolvable(): void
    {
        $service = $this->app->make(FlowBuilderService::class);

        $this->assertInstanceOf(FlowBuilderService::class, $service);
    }

    public function test_the_service_gets_its_own_guarded_llm_client_not_the_shared_singleton(): void
    {
        // Same guardrail-misattribution risk as BoundedAgentNode (see
        // docs/LESSON.md, F-PR6): sharing the 'ai.llm.prompt'-scoped
        // singleton here would let this service's calls authorize/rate-limit
        // under the WRONG identity.
        $sharedPromptClient = $this->app->make(LlmClient::class);

        $service = $this->app->make(FlowBuilderService::class);
        $clientProperty = (new ReflectionClass($service))->getProperty('client');
        $serviceClient = $clientProperty->getValue($service);

        $this->assertNotSame($sharedPromptClient, $serviceClient);
    }
}
