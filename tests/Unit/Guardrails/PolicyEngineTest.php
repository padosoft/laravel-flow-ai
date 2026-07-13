<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Guardrails;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Padosoft\LaravelFlowAI\Guardrails\PolicyEngine;
use PHPUnit\Framework\TestCase;

final class PolicyEngineTest extends TestCase
{
    private function cache(): CacheRepository
    {
        return new CacheRepository(new ArrayStore);
    }

    public function test_unconfigured_engine_allows_everything(): void
    {
        $engine = new PolicyEngine;

        $decision = $engine->authorize('ai.llm.prompt', 'api.anthropic.com');

        $this->assertTrue($decision->allowed);
        $this->assertNull($decision->reason);
    }

    public function test_per_node_type_permission_denial(): void
    {
        $engine = new PolicyEngine(allowedNodeTypes: ['ai.llm.prompt']);

        $allowed = $engine->authorize('ai.llm.prompt', 'api.anthropic.com');
        $denied = $engine->authorize('ai.mcp.client', 'api.anthropic.com');

        $this->assertTrue($allowed->allowed);
        $this->assertFalse($denied->allowed);
        $this->assertStringContainsString('ai.mcp.client', (string) $denied->reason);
    }

    public function test_egress_allowlist_denies_a_host_not_on_the_list(): void
    {
        $engine = new PolicyEngine(egressAllowlist: ['api.anthropic.com']);

        $allowed = $engine->authorize('ai.llm.prompt', 'api.anthropic.com');
        $denied = $engine->authorize('ai.llm.prompt', 'evil.example.com');

        $this->assertTrue($allowed->allowed);
        $this->assertFalse($denied->allowed);
        $this->assertStringContainsString('evil.example.com', (string) $denied->reason);
    }

    public function test_egress_allowlist_glob_matches_subdomains_only(): void
    {
        $engine = new PolicyEngine(egressAllowlist: ['*.anthropic.com']);

        $subdomain = $engine->authorize('ai.llm.prompt', 'api.anthropic.com');
        $bareDomain = $engine->authorize('ai.llm.prompt', 'anthropic.com');

        $this->assertTrue($subdomain->allowed, 'a *.suffix glob matches a real subdomain');
        $this->assertFalse($bareDomain->allowed, 'a *.suffix glob does NOT match the bare suffix itself');
    }

    public function test_rate_limit_denies_after_threshold(): void
    {
        $engine = new PolicyEngine(rateLimitMaxAttempts: 2, rateLimitDecaySeconds: 60, cache: $this->cache());

        $first = $engine->authorize('ai.llm.prompt', 'api.anthropic.com');
        $second = $engine->authorize('ai.llm.prompt', 'api.anthropic.com');
        $third = $engine->authorize('ai.llm.prompt', 'api.anthropic.com');

        $this->assertTrue($first->allowed);
        $this->assertTrue($second->allowed);
        $this->assertFalse($third->allowed);
        $this->assertStringContainsString('rate limit', (string) $third->reason);
    }

    public function test_rate_limit_is_scoped_per_node_type(): void
    {
        $cache = $this->cache();
        $engine = new PolicyEngine(rateLimitMaxAttempts: 1, cache: $cache);

        $first = $engine->authorize('ai.llm.prompt', 'api.anthropic.com');
        $second = $engine->authorize('ai.mcp.client', 'api.anthropic.com');

        $this->assertTrue($first->allowed);
        $this->assertTrue($second->allowed, 'a different node type has its own independent rate-limit budget');
    }

    public function test_zero_rate_limit_max_attempts_means_unlimited(): void
    {
        $engine = new PolicyEngine(rateLimitMaxAttempts: 0, cache: $this->cache());

        for ($i = 0; $i < 50; $i++) {
            $decision = $engine->authorize('ai.llm.prompt', 'api.anthropic.com');
            $this->assertTrue($decision->allowed);
        }
    }

    public function test_rate_limit_without_a_cache_is_a_no_op(): void
    {
        // cache: null (the default) — a rate limit configured but no cache
        // bound must not throw or silently deny; it degrades to unlimited.
        $engine = new PolicyEngine(rateLimitMaxAttempts: 1);

        $first = $engine->authorize('ai.llm.prompt', 'api.anthropic.com');
        $second = $engine->authorize('ai.llm.prompt', 'api.anthropic.com');

        $this->assertTrue($first->allowed);
        $this->assertTrue($second->allowed);
    }

    public function test_node_type_permission_is_checked_before_rate_limit(): void
    {
        // A denied node type must not consume rate-limit budget — cheapest
        // check first, and a permission denial should never have a side
        // effect on shared rate-limit state.
        $cache = $this->cache();
        $engine = new PolicyEngine(allowedNodeTypes: ['ai.llm.prompt'], rateLimitMaxAttempts: 1, cache: $cache);

        $engine->authorize('ai.mcp.client', 'api.anthropic.com');
        $engine->authorize('ai.mcp.client', 'api.anthropic.com');

        $this->assertFalse($cache->has('laravel-flow-ai:policy-rate-limit:ai.mcp.client'));
    }
}
