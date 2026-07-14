<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Console\Commands;

use Illuminate\Console\Command;
use Padosoft\LaravelFlowAI\Advisor\FlowAdvisor;
use Padosoft\LaravelFlowAI\Advisor\Suggestion;

/**
 * @internal
 */
final class ImproveFlowCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'flow:improve {flow : Flow definition name to analyze}';

    /**
     * @var string
     */
    protected $description = 'Scan one flow\'s run history for deterministic Flow Advisor suggestions, saved as draft versions.';

    public function handle(FlowAdvisor $advisor): int
    {
        /** @var string $flow */
        $flow = $this->argument('flow');

        $suggestions = $advisor->improve($flow);

        if ($suggestions === []) {
            $this->info(sprintf('No suggestions found for [%s].', $flow));

            return self::SUCCESS;
        }

        foreach ($suggestions as $suggestion) {
            $this->renderSuggestion($suggestion);
        }

        $this->info(sprintf('%d suggestion(s) found for [%s].', count($suggestions), $flow));

        return self::SUCCESS;
    }

    private function renderSuggestion(Suggestion $suggestion): void
    {
        $this->line(sprintf(
            '[%s] %s — draft %s',
            $suggestion->finding->type,
            $suggestion->finding->summary,
            $suggestion->draftVersionId(),
        ));
        $this->line('  rationale: '.json_encode($suggestion->finding->rationale, JSON_THROW_ON_ERROR));
    }
}
