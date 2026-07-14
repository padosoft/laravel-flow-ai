<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Guardrails;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Padosoft\LaravelFlowAI\Guardrails\PolicyEngine;
use PHPUnit\Framework\TestCase;

final class PolicyEngineTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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
        $denied = $engine->authorize('ai.mcp.tool', 'api.anthropic.com');

        $this->assertTrue($allowed->allowed);
        $this->assertFalse($denied->allowed);
        $this->assertStringContainsString('ai.mcp.tool', (string) $denied->reason);
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
        $second = $engine->authorize('ai.mcp.tool', 'api.anthropic.com');

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

    public function test_rate_limit_window_is_fixed_not_extended_by_later_hits(): void
    {
        // Round-1 review (Codex): a naive get()/put() implementation resets
        // the TTL on every allowed call, turning a fixed window into an
        // ever-sliding one. Pin the FIXED behavior: a hit mid-window must
        // NOT push the window's expiry further out.
        Carbon::setTestNow('2026-01-01 00:00:00');
        $cache = $this->cache();
        $engine = new PolicyEngine(rateLimitMaxAttempts: 5, rateLimitDecaySeconds: 60, cache: $cache);

        $engine->authorize('ai.llm.prompt', 'api.anthropic.com'); // anchors the window at 00:00:00, expires 00:01:00

        Carbon::setTestNow('2026-01-01 00:00:30'); // 30s later, still inside the original window
        $engine->authorize('ai.llm.prompt', 'api.anthropic.com'); // must NOT push expiry to 00:01:30

        Carbon::setTestNow('2026-01-01 00:01:01'); // 61s after the FIRST hit — past the original window

        $this->assertNull(
            $cache->get('laravel-flow-ai:policy-rate-limit:ai.llm.prompt'),
            'the counter must have expired by the original window boundary, proving the TTL was never refreshed by the second hit'
        );
    }

    public function test_a_cache_backend_that_cannot_increment_atomically_fails_closed(): void
    {
        // Round-1 review (Codex + Copilot): increment() can return false on
        // an unusual backend. A rate limiter's job is to CAP calls, so an
        // anomaly here must deny, never silently allow unlimited calls.
        // Extends the REAL concrete Repository (backed by a real ArrayStore)
        // rather than hand-implementing the full Illuminate\Contracts\Cache\
        // Repository interface (which also extends the PSR-16 CacheInterface
        // with strictly-typed signatures) — only increment() is overridden.
        $brokenCache = new class(new ArrayStore) extends CacheRepository
        {
            public function increment($key, $value = 1): bool
            {
                return false;
            }
        };

        $engine = new PolicyEngine(rateLimitMaxAttempts: 5, cache: $brokenCache);

        $decision = $engine->authorize('ai.llm.prompt', 'api.anthropic.com');

        $this->assertFalse($decision->allowed);
    }

    public function test_egress_allowlist_is_case_insensitive(): void
    {
        $engine = new PolicyEngine(egressAllowlist: ['API.Anthropic.COM']);

        $decision = $engine->authorize('ai.llm.prompt', 'api.anthropic.com');

        $this->assertTrue($decision->allowed);
    }

    public function test_egress_allowlist_ignores_a_trailing_fqdn_dot(): void
    {
        $engine = new PolicyEngine(egressAllowlist: ['api.anthropic.com']);

        $decision = $engine->authorize('ai.llm.prompt', 'api.anthropic.com.');

        $this->assertTrue($decision->allowed, 'a trailing FQDN dot does not change host identity');
    }

    public function test_stdio_pseudo_host_allowlist_is_case_sensitive(): void
    {
        // Round-4 review: a `stdio:{command}` pseudo-host names a LOCAL
        // COMMAND to spawn (see McpClientNode), not a DNS hostname — RFC
        // 4343 case-insensitivity must NOT apply here, or an allowlist entry
        // for one executable would also authorize a differently-cased one on
        // a case-sensitive filesystem, silently widening a control meant to
        // gate arbitrary local code execution.
        $engine = new PolicyEngine(egressAllowlist: ['stdio:trusted-server']);

        $exactCase = $engine->authorize('ai.mcp.tool', 'stdio:trusted-server');
        $differentCase = $engine->authorize('ai.mcp.tool', 'stdio:Trusted-Server');

        $this->assertTrue($exactCase->allowed);
        $this->assertFalse($differentCase->allowed, 'a stdio: pseudo-host must be matched case-sensitively, unlike a DNS hostname');
    }

    public function test_stdio_pseudo_host_does_not_match_a_regular_hostname_pattern(): void
    {
        // A `*.suffix` glob (or any plain hostname pattern) is meaningless
        // for a local command name — a stdio: target must only ever match
        // another stdio:-prefixed allowlist entry, never fall through to
        // hostname-glob semantics.
        $engine = new PolicyEngine(egressAllowlist: ['*.example.com']);

        $decision = $engine->authorize('ai.mcp.tool', 'stdio:example.com');

        $this->assertFalse($decision->allowed);
    }

    public function test_node_type_permission_is_checked_before_rate_limit(): void
    {
        // A denied node type must not consume rate-limit budget — cheapest
        // check first, and a permission denial should never have a side
        // effect on shared rate-limit state.
        $cache = $this->cache();
        $engine = new PolicyEngine(allowedNodeTypes: ['ai.llm.prompt'], rateLimitMaxAttempts: 1, cache: $cache);

        $engine->authorize('ai.mcp.tool', 'api.anthropic.com');
        $engine->authorize('ai.mcp.tool', 'api.anthropic.com');

        $this->assertFalse($cache->has('laravel-flow-ai:policy-rate-limit:ai.mcp.tool'));
    }
}
