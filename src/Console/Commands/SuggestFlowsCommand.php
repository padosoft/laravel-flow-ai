<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Console\Commands;

use Illuminate\Console\Command;
use Padosoft\LaravelFlowAI\Advisor\FlowAdvisor;
use Padosoft\LaravelFlowAI\Advisor\Suggestion;

/**
 * @internal
 */
final class SuggestFlowsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'flow:suggest';

    /**
     * @var string
     */
    protected $description = 'Scan recent run history across every flow for deterministic Flow Advisor suggestions, saved as draft versions.';

    public function handle(FlowAdvisor $advisor): int
    {
        $suggestions = $advisor->suggest();

        if ($suggestions === []) {
            $this->info('No suggestions found.');

            return self::SUCCESS;
        }

        foreach ($suggestions as $suggestion) {
            $this->renderSuggestion($suggestion);
        }

        $this->info(sprintf('%d suggestion(s) found.', count($suggestions)));

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
