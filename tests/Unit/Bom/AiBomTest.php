<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Bom;

use DateTimeImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Padosoft\LaravelFlowAI\Bom\AiBom;
use Padosoft\LaravelFlowAI\Contracts\McpToolAuthorizer;
use Padosoft\LaravelFlowAI\Mcp\Authorization\AllowAllMcpToolAuthorizer;
use Padosoft\LaravelFlowAI\Mcp\Authorization\DenyAllMcpToolAuthorizer;
use Padosoft\LaravelFlowAI\Mcp\Pinning\PinRegistry;
use Padosoft\LaravelFlowAI\Mcp\Pinning\ToolPins;
use PHPUnit\Framework\TestCase;

final class AiBomTest extends TestCase
{
    public function test_the_document_declares_its_own_format(): void
    {
        $document = $this->bom()->toArray();

        $this->assertSame(AiBom::FORMAT, $document['bomFormat']);
        $this->assertSame(AiBom::SPEC_VERSION, $document['specVersion']);
    }

    public function test_the_digest_ignores_the_timestamp_so_an_unchanged_app_compares_equal(): void
    {
        // This is the whole point of the digest: a CI gate that fired on
        // every regeneration would be turned off within a week.
        $bom = $this->bom();

        $this->assertSame($bom->digest(), $bom->digest());
        $this->assertNotSame(
            $bom->toArray(new DateTimeImmutable('2026-01-01T00:00:00+00:00'))['generatedAt'],
            $bom->toArray(new DateTimeImmutable('2026-06-01T00:00:00+00:00'))['generatedAt'],
        );
    }

    public function test_a_changed_supply_chain_moves_the_digest(): void
    {
        $before = $this->bom()->digest();
        $after = $this->bom(pinnedServers: ['npx server' => ['search' => 'sha256:deadbeef']])->digest();

        $this->assertNotSame($before, $after);
    }

    public function test_a_loosened_control_moves_the_digest(): void
    {
        // The egress allowlist is part of the supply chain: widening it
        // changes what the AI stack may reach, which is exactly what a
        // reviewer wants the gate to catch.
        $before = $this->bom()->digest();
        $after = $this->bom(egressAllowlist: ['api.anthropic.com', 'anything.example'])->digest();

        $this->assertNotSame($before, $after);
    }

    public function test_no_api_key_ever_reaches_the_document(): void
    {
        // A BOM is meant to be committed, diffed and attached to a release.
        $document = json_encode($this->bom()->toArray(), JSON_THROW_ON_ERROR);

        $this->assertIsString($document);
        $this->assertStringNotContainsString('sk-ant-super-secret', $document);
        $this->assertStringNotContainsString('api_key', $document);
    }

    public function test_it_reports_the_authorizer_the_container_actually_hands_back_not_the_default(): void
    {
        // A host that rebound the MCP authorizer to something permissive
        // must see THAT in the BOM; reporting the package default would be
        // reassuring and false.
        $container = new Container;
        $container->bind(McpToolAuthorizer::class, AllowAllMcpToolAuthorizer::class);

        $document = $this->bom(container: $container)->toArray();

        $this->assertSame(AllowAllMcpToolAuthorizer::class, $document['controls']['authorizers'][McpToolAuthorizer::class]);
    }

    public function test_it_records_the_pinning_posture(): void
    {
        $document = $this->bom(mode: ToolPins::MODE_ENFORCE, pinnedServers: ['npx server' => ['search' => 'sha256:abc']])->toArray();

        $this->assertSame(ToolPins::MODE_ENFORCE, $document['controls']['mcpToolPinning']['mode']);
        $this->assertSame(1, $document['controls']['mcpToolPinning']['pinnedServers']);
        $this->assertSame([['name' => 'search', 'digest' => 'sha256:abc']], $document['mcpServers'][0]['tools']);
    }

    public function test_an_exposed_flow_with_no_definition_repository_is_marked_unresolved_rather_than_reachable(): void
    {
        $document = $this->bom(exposedFlows: ['send-welcome-email'])->toArray();

        $this->assertSame([['name' => 'send-welcome-email', 'status' => 'unresolved']], $document['exposedFlows']);
    }

    public function test_the_model_field_says_where_models_actually_come_from(): void
    {
        // Model ids are wired input ports chosen per execution, so a static
        // "models" list would be a field that is confidently wrong.
        $document = $this->bom()->toArray();

        $this->assertSame('api.anthropic.com', $document['providers'][0]['host']);
        $this->assertStringContainsString('per-execution', $document['providers'][0]['modelResolution']);
    }

    /**
     * @param  array<string, array<string, string>>  $pinnedServers
     * @param  list<string>  $egressAllowlist
     * @param  list<string>  $exposedFlows
     */
    private function bom(
        string $mode = ToolPins::MODE_OFF,
        array $pinnedServers = [],
        array $egressAllowlist = ['api.anthropic.com'],
        array $exposedFlows = [],
        ?Container $container = null,
    ): AiBom {
        $config = new Repository(['laravel-flow-ai' => [
            'anthropic' => [
                'api_key' => 'sk-ant-super-secret',
                'base_url' => 'https://api.anthropic.com/v1/messages',
                'api_version' => '2023-06-01',
                'timeout_seconds' => 30,
            ],
            'guardrails' => [
                'allowed_node_types' => ['ai.llm.prompt'],
                'egress_allowlist' => $egressAllowlist,
                'rate_limit_max_attempts' => 0,
                'rate_limit_decay_seconds' => 60,
            ],
            'mcp' => ['exposed_flows' => $exposedFlows],
            'agent' => ['allowed_tools' => [], 'max_iterations' => 5, 'max_total_tokens' => 4000],
        ]]);

        $container ??= new Container;

        if (! $container->bound(McpToolAuthorizer::class)) {
            $container->bind(McpToolAuthorizer::class, DenyAllMcpToolAuthorizer::class);
        }

        return new AiBom(
            config: $config,
            container: $container,
            pins: new PinRegistry($mode, servers: $pinnedServers),
        );
    }
}
