<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Guardrails;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Padosoft\LaravelFlowAI\Nodes\McpClientNode;

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
     * @param  list<string>  $egressAllowlist  empty = every host allowed; entries are exact hostnames or a `*.suffix` glob (matches any subdomain of `suffix`, not `suffix` itself) — EXCEPT a `stdio:` prefixed entry (the {@see McpClientNode} pseudo-host for a spawned local command), which is matched as an exact, CASE-SENSITIVE string: lowercasing a shell command name would make an allowlist meant to gate arbitrary local code execution accept a different, unintended executable on a case-sensitive filesystem
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

        // FIXED window, race-free under concurrent callers: add() atomically
        // creates the counter at 0 with the window's TTL ONLY the first time
        // (a no-op if another concurrent call already created it — the TTL is
        // therefore anchored to the FIRST hit in the window, never refreshed
        // by later hits, so this is a fixed window, not an ever-sliding one),
        // then increment() atomically bumps and returns the new value in one
        // cache round-trip — no separate get()-then-put() where two
        // concurrent callers could both read the same pre-increment count and
        // both pass the threshold check.
        $this->cache->add($key, 0, $this->rateLimitDecaySeconds);
        $attempts = $this->cache->increment($key);

        // A non-int return means the cache backend couldn't increment
        // atomically (an anomaly, not an expected outcome) — fail CLOSED
        // (deny) rather than open: this gate exists specifically to cap
        // outbound-call cost/abuse, so silently allowing unlimited calls on
        // a cache hiccup would defeat its entire purpose.
        if (! is_int($attempts) || $attempts > $this->rateLimitMaxAttempts) {
            return PolicyDecision::deny(
                "rate limit exceeded for node type [{$nodeType}] ({$this->rateLimitMaxAttempts} per {$this->rateLimitDecaySeconds}s)"
            );
        }

        return PolicyDecision::allow();
    }

    private function hostAllowed(string $host): bool
    {
        // A `stdio:{command}` pseudo-host names a LOCAL COMMAND to spawn, not
        // a DNS hostname — RFC 4343 case-insensitivity does not apply, and
        // lowercasing it would let an allowlist entry for one executable
        // (`stdio:trusted`) also authorize a DIFFERENT one (`stdio:TrUsTeD`)
        // on a case-sensitive filesystem, silently widening a control meant
        // to gate arbitrary local code execution. Compare it verbatim
        // against only the `stdio:`-prefixed allowlist entries — no glob
        // subdomain semantics either, since that concept doesn't apply to a
        // command name.
        if (str_starts_with($host, 'stdio:')) {
            return in_array($host, $this->egressAllowlist, true);
        }

        // Hostnames are case-insensitive (RFC 4343) and a trailing dot marks
        // a fully-qualified domain name without changing its identity
        // ("api.anthropic.com" and "api.anthropic.com." are the same host)
        // — normalize BOTH the incoming host and every configured pattern
        // the same way, so an allowlist entered with different casing/an
        // FQDN trailing dot doesn't silently deny a legitimate host.
        $host = self::normalizeHost($host);

        foreach ($this->egressAllowlist as $pattern) {
            if (str_starts_with($pattern, 'stdio:')) {
                continue;
            }

            $pattern = self::normalizeHost($pattern);

            if ($pattern === $host) {
                return true;
            }

            if (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1))) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeHost(string $host): string
    {
        return rtrim(strtolower($host), '.');
    }
}
