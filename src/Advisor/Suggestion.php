<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Advisor;

/**
 * A {@see Finding} paired with the DRAFT version it was persisted onto —
 * `FlowAdvisor` NEVER returns a `Finding` without also creating (or
 * reusing) a draft `GraphDefinition` version for it, and never publishes
 * one: a human (today, `flow:approve`-style review; later, Macro E's UI)
 * always decides whether a suggestion becomes real.
 *
 * @api
 */
final readonly class Suggestion
{
    public function __construct(
        public string $definitionName,
        public int $draftVersion,
        public Finding $finding,
    ) {}

    public function draftVersionId(): string
    {
        return "{$this->definitionName}@{$this->draftVersion}";
    }
}
