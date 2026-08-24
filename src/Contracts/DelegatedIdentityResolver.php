<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Contracts;

use Padosoft\LaravelFlowAI\Identity\DelegatedIdentity;
use Padosoft\LaravelFlowAI\Identity\Exceptions\GrantRevokedException;
use Padosoft\LaravelFlowAI\Nodes\BoundedAgentNode;

/**
 * Resolves the {@see DelegatedIdentity} the CURRENT run acts under —
 * this package's seam toward an external identity provider, owned here so
 * `laravel-flow-ai` depends on no IAM package. The reference implementation
 * lives with `padosoft/laravel-iam-agents`: it exchanges the delegating
 * user's token via OAuth 2.0 Token Exchange (RFC 8693) for a short-lived
 * delegated access token carrying BOTH identities (`sub` = user, `act` =
 * agent), re-exchanging when the cached token nears expiry.
 *
 * **Not bound by default.** {@see BoundedAgentNode}
 * takes this as an OPTIONAL dependency (null = no delegated identity, the
 * pre-existing behavior). A host (or the IAM bridge) binds an implementation
 * — typically container-SCOPED so each run resolves a fresh identity rather
 * than reusing another run's token.
 *
 * Contract, fail-closed by design:
 *  - return the CURRENT, still-valid identity (implementations refresh /
 *    re-exchange internally; callers never see an expired token);
 *  - return `null` when this run simply has no delegated identity (a
 *    system-initiated run, an unconfigured host) — that is not an error;
 *  - THROW {@see GrantRevokedException} when the delegation grant was
 *    revoked or the delegating user's session ended: revocation must halt
 *    the run, never degrade silently into `null` ("no identity" would let
 *    an agent keep acting exactly when it was just told to stop).
 *
 * @api
 */
interface DelegatedIdentityResolver
{
    /**
     * @throws GrantRevokedException when the grant behind this run's identity has been revoked
     */
    public function resolve(): ?DelegatedIdentity;
}
