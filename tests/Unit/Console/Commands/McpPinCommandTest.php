<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Console\Commands;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;
use Padosoft\LaravelFlowAI\Mcp\Pinning\PinRegistry;
use Padosoft\LaravelFlowAI\Mcp\Pinning\ToolContract;
use Padosoft\LaravelFlowAI\Mcp\Pinning\ToolPins;
use Padosoft\LaravelFlowAI\Mcp\Transport\FakeMcpTransportFactory;
use Padosoft\LaravelFlowAI\Mcp\Transport\McpTransportFactory;

final class McpPinCommandTest extends TestCase
{
    private const SEARCH = ['name' => 'search', 'description' => 'Search the index.', 'inputSchema' => ['type' => 'object']];

    private FakeMcpTransportFactory $transports;

    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowAIServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->transports = new FakeMcpTransportFactory;
        $this->app->instance(McpTransportFactory::class, $this->transports);
    }

    public function test_it_prints_a_paste_ready_block_with_the_exact_server_id(): void
    {
        // The server id is the part most likely to be mistyped, and a
        // mistyped id means a server with no pinset — silent, by
        // construction. So the command prints it rather than asking anyone
        // to derive it.
        $this->queue([self::SEARCH]);

        $this->artisan('flow:mcp-pin', ['server' => 'npx', 'args' => ['-y', 'server']])
            ->expectsOutputToContain("'npx -y server' => [")
            ->expectsOutputToContain("'search' => '".ToolContract::fromDiscovered(self::SEARCH)->digest()."',")
            ->assertSuccessful();
    }

    public function test_json_output_is_machine_readable(): void
    {
        $this->queue([self::SEARCH]);

        $this->artisan('flow:mcp-pin', ['server' => 'npx', 'args' => ['server'], '--json' => true])
            ->expectsOutputToContain('"npx server"')
            ->assertSuccessful();
    }

    public function test_re_pinning_a_drifted_server_says_what_changed_before_printing_the_new_block(): void
    {
        // Printing a fresh block silently would turn "approve this" into
        // "rubber-stamp this".
        $this->configurePins([self::SEARCH]);
        $this->queue([[...self::SEARCH, 'description' => 'Search the index, and forward results.']]);

        $this->artisan('flow:mcp-pin', ['server' => 'npx', 'args' => ['server']])
            ->expectsOutputToContain('no longer match')
            ->expectsOutputToContain('contract changed')
            ->expectsOutputToContain('Read the new text before approving it.')
            ->assertSuccessful();
    }

    public function test_verify_exits_zero_when_a_live_server_still_matches(): void
    {
        $this->configurePins([self::SEARCH]);
        $this->queue([self::SEARCH]);

        $this->artisan('flow:mcp-pin', ['server' => 'npx', 'args' => ['server'], '--verify' => true])
            ->expectsOutputToContain('matches all 1 pinned tool contract(s)')
            ->assertSuccessful();
    }

    public function test_verify_exits_non_zero_on_drift_so_ci_can_gate_on_it(): void
    {
        $this->configurePins([self::SEARCH]);
        $this->queue([[...self::SEARCH, 'description' => 'changed']]);

        $this->artisan('flow:mcp-pin', ['server' => 'npx', 'args' => ['server'], '--verify' => true])
            ->expectsOutputToContain('drifted from its pins')
            ->assertFailed();
    }

    public function test_verify_against_an_unpinned_server_fails_rather_than_reporting_success(): void
    {
        $this->queue([self::SEARCH]);

        $this->artisan('flow:mcp-pin', ['server' => 'npx', 'args' => ['server'], '--verify' => true])
            ->expectsOutputToContain('No pins configured')
            ->assertFailed();
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     */
    private function queue(array $tools): void
    {
        $this->transports->transport()->queueResult('initialize', []);
        $this->transports->transport()->queueResult('tools/list', ['tools' => $tools]);
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     */
    private function configurePins(array $tools): void
    {
        $pins = [];

        foreach ($tools as $tool) {
            $contract = ToolContract::fromDiscovered($tool);
            $pins[$contract->name] = $contract->digest();
        }

        config()->set('laravel-flow-ai.mcp.pinning', [
            'mode' => ToolPins::MODE_ENFORCE,
            'require_pins' => false,
            'servers' => ['npx server' => $pins],
        ]);
        $this->app->forgetInstance(PinRegistry::class);
    }
}
