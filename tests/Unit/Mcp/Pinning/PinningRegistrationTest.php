<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Mcp\Pinning;

use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlowAI\Bom\AiBom;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;
use Padosoft\LaravelFlowAI\Mcp\Pinning\PinRegistry;
use Padosoft\LaravelFlowAI\Mcp\Pinning\ToolPins;

final class PinningRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowAIServiceProvider::class];
    }

    public function test_pinning_is_off_on_a_fresh_install(): void
    {
        // A package upgrade must never start failing a host's runs against
        // pins they never wrote.
        $this->assertSame(ToolPins::MODE_OFF, $this->app->make(PinRegistry::class)->mode());
        $this->assertNull($this->app->make(PinRegistry::class)->forServer('npx', ['server']));
    }

    public function test_the_registry_is_one_shared_singleton(): void
    {
        $this->assertSame($this->app->make(PinRegistry::class), $this->app->make(PinRegistry::class));
    }

    public function test_configured_pins_reach_the_registry_with_their_server_id_normalised(): void
    {
        config()->set('laravel-flow-ai.mcp.pinning', [
            'mode' => ToolPins::MODE_ENFORCE,
            'require_pins' => false,
            'servers' => ['  npx   -y   server ' => ['search' => 'sha256:abc']],
        ]);
        $this->app->forgetInstance(PinRegistry::class);

        $registry = $this->app->make(PinRegistry::class);

        $this->assertSame(['npx -y server' => ['search' => 'sha256:abc']], $registry->pinnedServers());
        $this->assertNotNull($registry->forServer('npx', ['-y', 'server']));
    }

    public function test_the_ai_bom_resolves_and_reports_the_configured_posture(): void
    {
        config()->set('laravel-flow-ai.mcp.pinning.mode', ToolPins::MODE_WARN);
        $this->app->forgetInstance(PinRegistry::class);

        $document = $this->app->make(AiBom::class)->toArray();

        $this->assertSame(AiBom::FORMAT, $document['bomFormat']);
        $this->assertSame(ToolPins::MODE_WARN, $document['controls']['mcpToolPinning']['mode']);
    }

    public function test_the_commands_are_registered(): void
    {
        $commands = array_keys($this->app->make(Kernel::class)->all());

        $this->assertContains('flow:ai-bom', $commands);
        $this->assertContains('flow:mcp-pin', $commands);
    }
}
