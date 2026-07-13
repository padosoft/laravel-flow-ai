<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Guardrails;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Enforces this package's outbound-call guardrails BEFORE any network call —
 * consulted by {@see GuardedLlmClient} (and, in a future PR, the MCP client
 * node) via a single {@see authorize()} check that covers three independent
 * gates: per-node-type permission, egress host allowlist, and a rate limit.
 * ANY gate denying short-circuits the others (cheapest checks first: no I/O,
 * no cache round-trip, before the rate-limit check that needs one).
 *
 * Every gate is PERMISSIVE when left unconfigured (empty allowlist = every
 * node type / host allowed; `$rateLimitMaxAttempts = 0` = unlimited) — same
 * "the mechanism always runs, defaults stay open" posture as this program's
 * other opt-in subsystems (core's `broadcasting.enabled` defaults `false`,
 * but here the ENGINE itself is always consulted; only its rules default to
 * no-op, so a host app tightening config later needs no code change).
 *
 * @api
 */
final class PolicyEngine
{
    /**
     * @param  list<string>  $allowedNodeTypes  empty = every node type allowed
     * @param  list<string>  $egressAllowlist  empty = every host allowed; entries are exact hostnames or a `*.suffix` glob (matches any subdomain of `suffix`, not `suffix` itself)
     */
    public function __construct(
        private readonly array $allowedNodeTypes = [],
        private readonly array $egressAllowlist = [],
        private readonly int $rateLimitMaxAttempts = 0,
        private readonly int $rateLimitDecaySeconds = 60,
        private readonly ?CacheRepository $cache = null,
    ) {}

    public function authorize(string $nodeType, string $targetHost): PolicyDecision
    {
        if ($this->allowedNodeTypes !== [] && ! in_array($nodeType, $this->allowedNodeTypes, true)) {
            return PolicyDecision::deny("node type [{$nodeType}] is not permitted to make outbound AI calls");
        }

        if ($this->egressAllowlist !== [] && ! $this->hostAllowed($targetHost)) {
            return PolicyDecision::deny("host [{$targetHost}] is not in the egress allowlist");
        }

        return $this->checkRateLimit($nodeType);
    }

    private function checkRateLimit(string $nodeType): PolicyDecision
    {
        if ($this->rateLimitMaxAttempts <= 0 || $this->cache === null) {
            return PolicyDecision::allow();
        }

        $key = "laravel-flow-ai:policy-rate-limit:{$nodeType}";
        $attempts = (int) $this->cache->get($key, 0);

        if ($attempts >= $this->rateLimitMaxAttempts) {
            return PolicyDecision::deny(
                "rate limit exceeded for node type [{$nodeType}] ({$this->rateLimitMaxAttempts} per {$this->rateLimitDecaySeconds}s)"
            );
        }

        // Record BEFORE returning allow(): the call this decision authorizes
        // is about to happen, so it must count toward the window immediately
        // — recording only on a later "call completed" signal would let a
        // burst of concurrent/rapid calls all read the same pre-increment
        // count and all be allowed, exceeding the limit.
        $this->cache->put($key, $attempts + 1, $this->rateLimitDecaySeconds);

        return PolicyDecision::allow();
    }

    private function hostAllowed(string $host): bool
    {
        foreach ($this->egressAllowlist as $pattern) {
            if ($pattern === $host) {
                return true;
            }

            if (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1))) {
                return true;
            }
        }

        return false;
    }
}
