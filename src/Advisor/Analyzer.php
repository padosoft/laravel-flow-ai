<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Advisor;

use Padosoft\LaravelFlow\Dashboard\RunDetail;

/**
 * One deterministic, no-LLM, no-network history analysis over a single
 * flow's sampled run history — `FlowAdvisor` runs every bound analyzer
 * against the same sample and merges their findings. Implementations MUST
 * be pure functions of `$runs` (and `$definitionName`): no I/O, no
 * randomness, no clock reads, so the exact same history always produces the
 * exact same findings.
 *
 * @api
 */
interface Analyzer
{
    /**
     * @param  list<RunDetail>  $runs  most-recent-first, already scoped to one flow
     * @return list<Finding>
     */
    public function analyze(string $definitionName, array $runs): array;
}
