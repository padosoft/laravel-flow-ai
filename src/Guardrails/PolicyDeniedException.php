<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Guardrails;

use Padosoft\LaravelFlow\Node\NodeResult;
use RuntimeException;

/**
 * Thrown by {@see GuardedLlmClient} when {@see PolicyEngine::authorize()}
 * denies a call. A caller (a node's `execute()`) catches this like any other
 * `Throwable` and maps it to a {@see NodeResult::failed()}
 * — the same as any other pre-flight failure — never an uncaught exception.
 *
 * @api
 */
final class PolicyDeniedException extends RuntimeException {}
