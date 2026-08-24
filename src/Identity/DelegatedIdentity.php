<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Identity;

use Padosoft\LaravelFlowAI\Contracts\DelegatedIdentityResolver;
use SensitiveParameter;

/**
 * The delegated identity a run acts under: WHO the work is for (`$subject`,
 * e.g. `user:42`), WHICH agent identity performs it (`$actor`, e.g.
 * `agent:01J…`), and the short-lived delegated access token that proves the
 * pairing — typically minted via an OAuth 2.0 Token Exchange (RFC 8693) by an
 * authorization server such as `padosoft/laravel-iam-server` with the
 * `laravel-iam-agents` module, though this package deliberately depends on no
 * IAM package: any {@see DelegatedIdentityResolver}
 * implementation can produce one.
 *
 * **The token is a secret.** It is held in a private property, excluded from
 * `var_dump()` via {@see __debugInfo()}, and marked `#[SensitiveParameter]`
 * so stack traces redact it. It must never enter a flow run's input (core
 * persists `flow_runs.input` unredacted), a transcript, or a log — the ONLY
 * sanctioned egress is {@see environment()}, which hands it to a spawned MCP
 * tool server as process environment.
 *
 * @api
 */
final readonly class DelegatedIdentity
{
    /**
     * Environment variable names used by {@see environment()} — a stable,
     * documented contract for MCP tool servers that consume the handoff.
     */
    public const ENV_TOKEN = 'FLOW_DELEGATED_TOKEN';

    public const ENV_SUBJECT = 'FLOW_DELEGATED_SUBJECT';

    public const ENV_ACTOR = 'FLOW_DELEGATED_ACTOR';

    /**
     * @param  string  $subject  who the run acts FOR (e.g. "user:42")
     * @param  string  $actor  the agent identity performing the work (e.g. "agent:01J…")
     * @param  string  $token  the short-lived delegated access token (secret — see class doc)
     * @param  string|null  $grantId  the delegation grant this token was minted under, when known — the citable revocation anchor
     * @param  list<string>  $scopes  the scopes actually granted on the token (informational; the authorization server, not this VO, is the authority)
     * @param  string|null  $audience  the audience/resource the token is bound to, when known
     */
    public function __construct(
        public string $subject,
        public string $actor,
        #[SensitiveParameter]
        private string $token,
        public ?string $grantId = null,
        public array $scopes = [],
        public ?string $audience = null,
    ) {}

    public function token(): string
    {
        return $this->token;
    }

    /**
     * The environment variables to hand a spawned MCP tool server — the only
     * sanctioned way the token leaves this object. Process environment
     * reaches the child process alone: it is never persisted by core, never
     * embedded in an LLM prompt, and never part of a tool call's arguments.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        return [
            self::ENV_TOKEN => $this->token,
            self::ENV_SUBJECT => $this->subject,
            self::ENV_ACTOR => $this->actor,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'subject' => $this->subject,
            'actor' => $this->actor,
            'token' => '[redacted]',
            'grantId' => $this->grantId,
            'scopes' => $this->scopes,
            'audience' => $this->audience,
        ];
    }
}
