<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Guardrails;

use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlowAI\Nodes\LlmPromptNode;
use RuntimeException;

/**
 * Thrown by {@see GuardedLlmClient} when {@see PolicyEngine::authorize()}
 * denies a call. A node calling through a guarded client (e.g.
 * {@see LlmPromptNode}) MUST catch this
 * EXPLICITLY around the call site and map it to a {@see NodeResult::failed()}
 * immediately — never retried (a policy denial is an infra/permission
 * concern, not a data-shape one a retry loop could resolve) and never left
 * to escape uncaught, since a `LlmClient` consumer constructed directly
 * (bypassing the container / core's executor) has no outer catch to rely on.
 *
 * @api
 */
final class PolicyDeniedException extends RuntimeException {}
