<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Advisor;

use DateTimeImmutable;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Dashboard\FlowDashboardReadModel;
use Padosoft\LaravelFlow\Dashboard\Pagination;
use Padosoft\LaravelFlow\Dashboard\RunDetail;
use Padosoft\LaravelFlow\Dashboard\RunFilter;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Graph\StoredDefinition;

/**
 * Deterministic flow-history advisor: runs every bound {@see Analyzer}
 * against a flow's sampled run history (via core's `FlowDashboardReadModel`
 * dashboard read contract — never raw `flow_run_nodes`/`flow_runs` table
 * queries, keeping this package on the same "route through contracts"
 * discipline every other satellite package follows) and turns each
 * resulting {@see Finding} into a {@see Suggestion}: a `Finding` paired
 * with a DRAFT `GraphDefinition` version that carries the finding's
 * rationale in its `metadata['advisor']` key.
 *
 * No LLM call anywhere in this class or any bound {@see Analyzer} — every
 * finding is deterministically derived from persisted run history. A
 * finding's `rationale` still passes through the bound {@see PayloadRedactor}
 * (when wired) before it reaches a draft version or a caller: `RunDetail`'s
 * own docblock is explicit that dashboard-read data is UNREDACTED whenever
 * a host has `laravel-flow.persistence.redaction.enabled` set to `false`,
 * so this is the one place that guarantee is enforced for Advisor output
 * regardless of that host setting.
 *
 * Suggestions are ALWAYS drafts (`StoredDefinition::STATUS_DRAFT`) — this
 * class calls `DefinitionRepository::createDraft()`, never `publish()`. A
 * human (today: reviewing the draft directly; later: Macro E's UI) always
 * decides whether a suggestion becomes real.
 *
 * @api
 */
final class FlowAdvisor
{
    /**
     * @param  list<Analyzer>  $analyzers
     * @param  list<string>  $exposedFlowNames  MCP-exposed flow names (config `mcp.exposed_flows`) — considered as `suggest()` candidates even with zero run history, so `Analyzers\UnusedToolAnalyzer` can flag a declared-but-never-run flow
     */
    public function __construct(
        private readonly FlowDashboardReadModel $readModel,
        private readonly DefinitionRepository $definitions,
        private readonly array $analyzers,
        private readonly array $exposedFlowNames = [],
        private readonly ?PayloadRedactor $redactor = null,
        private readonly int $sampleSize = 50,
    ) {}

    /**
     * Scans every candidate flow (recent run history plus configured
     * MCP-exposed names) and returns every suggestion found across all of
     * them.
     *
     * @return list<Suggestion>
     */
    public function suggest(): array
    {
        $suggestions = [];

        foreach ($this->candidateDefinitionNames() as $definitionName) {
            array_push($suggestions, ...$this->improve($definitionName));
        }

        return $suggestions;
    }

    /**
     * Scans ONE named flow's run history and returns every suggestion
     * found — an empty list means every bound analyzer found nothing
     * notable, not an error.
     *
     * @return list<Suggestion>
     */
    public function improve(string $definitionName): array
    {
        $runs = $this->sampleRuns($definitionName);

        $findings = [];

        foreach ($this->analyzers as $analyzer) {
            array_push($findings, ...$analyzer->analyze($definitionName, $runs));
        }

        if ($findings === []) {
            return [];
        }

        $redacted = array_map($this->redactFinding(...), $findings);

        $draft = $this->createDraft($definitionName, $redacted);

        if ($draft === null) {
            return [];
        }

        return array_map(
            static fn (Finding $finding): Suggestion => new Suggestion($definitionName, $draft->version, $finding),
            $redacted,
        );
    }

    /**
     * A key-based `PayloadRedactor` (core's `KeyBasedPayloadRedactor`, the
     * usual binding) matches on DICT KEYS — it cannot catch a secret
     * embedded INSIDE a free-text string value like `Analyzers\FailureHotspotAnalyzer`'s
     * `sample_error_messages` (a list of raw exception message TEXT, e.g.
     * "db connection failed: password=hunter2"). Core has its own
     * `Persistence\ErrorMessageRedactor` for exactly this free-text case,
     * but it is `@internal` — this package cannot depend on it directly
     * per this program's `@api`/`@internal` stability discipline, so
     * {@see stripFreeTextSecrets()} is a small, independently-maintained
     * equivalent scoped to this package's own rationale payloads: an
     * unconditional `Bearer <token>` strip (the single highest-value,
     * always-safe heuristic) plus a best-effort `key=value`/`key: value`
     * scrub for a small fixed set of common secret-ish key names. This is
     * NOT a substitute for a host's own logging discipline — it exists so
     * a raw exception message can never reach a PERSISTED draft version
     * carrying an obviously-shaped secret untouched.
     */
    private function redactFinding(Finding $finding): Finding
    {
        $rationale = $this->stripFreeTextSecrets($finding->rationale);

        if ($this->redactor !== null) {
            $rationale = $this->redactor->redact($rationale);
        }

        return new Finding($finding->type, $finding->summary, $rationale);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function stripFreeTextSecrets(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $value[$key] = $this->stripFreeTextSecretsFromString($item);
            } elseif (is_array($item)) {
                /** @var array<string, mixed> $item */
                $value[$key] = $this->stripFreeTextSecrets($item);
            }
        }

        return $value;
    }

    private function stripFreeTextSecretsFromString(string $text): string
    {
        $text = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', $text) ?? $text;

        return preg_replace_callback(
            '/\b(password|secret|token|api[_-]?key|authorization)\b(\s*[:=]\s*)([^\s,;]+)/i',
            static fn (array $matches): string => $matches[1].$matches[2].'[redacted]',
            $text,
        ) ?? $text;
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function createDraft(string $definitionName, array $findings): ?StoredDefinition
    {
        $latest = $this->definitions->latest($definitionName);

        if ($latest === null) {
            return null;
        }

        $serializer = new GraphSerializer;
        $graph = $serializer->fromArray($latest->graph);

        $withRationale = new GraphDefinition(
            $graph->nodes,
            $graph->connections,
            [
                ...$graph->metadata,
                'advisor' => [
                    'generated_at' => (new DateTimeImmutable)->format(DATE_ATOM),
                    'based_on_version' => $latest->version,
                    'findings' => array_map(
                        static fn (Finding $f): array => ['type' => $f->type, 'summary' => $f->summary, 'rationale' => $f->rationale],
                        $findings,
                    ),
                ],
            ],
        );

        return $this->definitions->createDraft($definitionName, $withRationale);
    }

    /**
     * @return list<RunDetail>
     */
    private function sampleRuns(string $definitionName): array
    {
        $filter = new RunFilter(definitionName: $definitionName);
        // Clamped to [1, MAX_PER_PAGE]: Pagination's own constructor throws
        // on a sub-1 perPage, so a misconfigured advisor.sample_size (0 or
        // negative) must never reach it directly.
        $perPage = max(1, min($this->sampleSize, Pagination::MAX_PER_PAGE));
        $page = $this->readModel->listRuns($filter, new Pagination(page: 1, perPage: $perPage));

        $details = [];

        foreach ($page->items as $summary) {
            $detail = $this->readModel->findRun($summary->id);

            if ($detail !== null) {
                $details[] = $detail;
            }
        }

        return $details;
    }

    /**
     * Recent run history (bounded to the read model's own page-size ceiling
     * — a deliberate v1 scope bound, not silent unbounded scanning) union
     * the configured MCP-exposed flow names, so a flow with ZERO runs is
     * still a `suggest()` candidate.
     *
     * @return list<string>
     */
    private function candidateDefinitionNames(): array
    {
        $page = $this->readModel->listRuns(new RunFilter, new Pagination(page: 1, perPage: Pagination::MAX_PER_PAGE));

        $fromHistory = array_map(static fn ($run): string => $run->definitionName, $page->items);

        return array_values(array_unique([...$fromHistory, ...$this->exposedFlowNames]));
    }
}
