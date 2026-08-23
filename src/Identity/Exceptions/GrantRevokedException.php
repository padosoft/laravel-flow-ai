<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Identity\Exceptions;

use Padosoft\LaravelFlowAI\Contracts\DelegatedIdentityResolver;
use Padosoft\LaravelFlowAI\Identity\DelegatedIdentity;
use Padosoft\LaravelFlowAI\Nodes\BoundedAgentNode;
use Padosoft\LaravelFlowAI\Nodes\Exceptions\AgentToolNotAllowedException;
use RuntimeException;

/**
 * The delegation grant behind a run's {@see DelegatedIdentity}
 * has been revoked (or the delegating user's session ended) — the run must
 * HALT, not continue on whatever token it still holds. Thrown by
 * {@see DelegatedIdentityResolver::resolve()}
 * and turned into a typed node failure by
 * {@see BoundedAgentNode} BEFORE the next tool
 * call happens (mirroring {@see AgentToolNotAllowedException}'s
 * "blocked before it happened" posture): revocation is a fail-closed stop
 * signal, never something to ride out until token expiry.
 *
 * @api
 */
final class GrantRevokedException extends RuntimeException
{
    public function __construct(
        public readonly ?string $grantId = null,
        string $message = 'The delegation grant behind this run has been revoked; the run was halted before the next tool call.',
    ) {
        parent::__construct($message);
    }
}
