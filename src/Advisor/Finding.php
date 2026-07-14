<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Advisor;

/**
 * One deterministic {@see Analyzer} observation about a flow's run history —
 * `$rationale` is machine-readable and JSON-serializable (a future Macro E
 * diff/suggestions UI is an explicit consumer of this shape, per the Macro F
 * plan's own grounding note), never free-text prose an LLM would need to
 * generate: every {@see Analyzer} in this package is deterministic, no LLM
 * call anywhere in the finding-production path.
 *
 * @api
 */
final readonly class Finding
{
    /**
     * @param  array<string, mixed>  $rationale
     */
    public function __construct(
        public string $type,
        public string $summary,
        public array $rationale,
    ) {}
}
