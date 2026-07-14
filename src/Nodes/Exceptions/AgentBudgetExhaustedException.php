<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Nodes\Exceptions;

use Padosoft\LaravelFlowAI\Nodes\BoundedAgentNode;
use RuntimeException;

/**
 * Thrown by {@see BoundedAgentNode} when one of
 * its hard budgets (iterations, total tokens, or — only when a cost rate is
 * configured — cost) is exhausted BEFORE another LLM call would be made.
 * `$budget` names which one ('iterations'|'tokens'|'cost') so a flow author
 * or the Flow Advisor can distinguish this outcome from a tool-execution
 * failure or "the model decided it was done" — never just a generic
 * `RuntimeException` a caller would have to string-match to interpret.
 *
 * @api
 */
final class AgentBudgetExhaustedException extends RuntimeException
{
    public function __construct(public readonly string $budget, string $message)
    {
        parent::__construct($message);
    }
}
