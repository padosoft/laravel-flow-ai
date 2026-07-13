<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Nodes\Exceptions;

use Padosoft\LaravelFlowAI\Nodes\BoundedAgentNode;
use RuntimeException;

/**
 * Thrown by {@see BoundedAgentNode} when the
 * model requests a tool NOT present in the node's configured allowlist. The
 * call is BLOCKED before it happens — the underlying MCP tool is never
 * invoked — and the loop halts immediately rather than feeding the denial
 * back for the model to try a different tool: an allowlist violation is a
 * SECURITY boundary, not a self-repairable data problem, so it gets the same
 * "never retried" treatment this program gives every other policy denial
 * (see `GuardedLlmClient`/`LlmPromptNode`'s handling of `PolicyDeniedException`).
 *
 * @api
 */
final class AgentToolNotAllowedException extends RuntimeException
{
    public function __construct(public readonly string $tool, string $message)
    {
        parent::__construct($message);
    }
}
