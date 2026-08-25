<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp\Pinning;

/**
 * One way an MCP server failed to match its pins. Four kinds, because they
 * are four different incidents and an operator has to tell them apart:
 *
 *   - **contract changed** — the tool is still there and still called the
 *     same thing, but its description/schema/annotations are not the ones
 *     that were approved. This is the rug pull, and the reason pinning
 *     exists at all.
 *   - **pinned tool missing** — a tool that was approved is no longer
 *     advertised. Benign as a deprecation, and indistinguishable from a
 *     downgrade attack that removes the safe tool so the model reaches for
 *     a different one; either way the server is not what was approved.
 *   - **unpinned tool** — the server advertises something nobody approved.
 *     Only a violation for a server that HAS a pinset, because a pinset is
 *     a statement that the catalog is closed (see {@see ToolPins}).
 *   - **server not pinned** — the whole server has no pinset at all, and
 *     the host asked for `require_pins`.
 *
 * `expected`/`actual` carry digests, never the contracts themselves: a tool
 * description can hold anything a server chose to put there, and this value
 * object ends up in logs and exception messages.
 *
 * @api
 */
final class PinViolation
{
    public const CONTRACT_CHANGED = 'contract_changed';

    public const PINNED_TOOL_MISSING = 'pinned_tool_missing';

    public const UNPINNED_TOOL = 'unpinned_tool';

    public const SERVER_NOT_PINNED = 'server_not_pinned';

    private function __construct(
        public readonly string $kind,
        public readonly string $tool,
        public readonly ?string $expected = null,
        public readonly ?string $actual = null,
    ) {}

    public static function contractChanged(string $tool, string $expected, string $actual): self
    {
        return new self(self::CONTRACT_CHANGED, $tool, $expected, $actual);
    }

    public static function pinnedToolMissing(string $tool, string $expected): self
    {
        return new self(self::PINNED_TOOL_MISSING, $tool, $expected);
    }

    public static function unpinnedTool(string $tool, string $actual): self
    {
        return new self(self::UNPINNED_TOOL, $tool, actual: $actual);
    }

    public static function serverNotPinned(): self
    {
        return new self(self::SERVER_NOT_PINNED, '*');
    }

    public function describe(): string
    {
        return match ($this->kind) {
            self::CONTRACT_CHANGED => sprintf('tool [%s] contract changed (pinned %s, server now %s)', $this->tool, (string) $this->expected, (string) $this->actual),
            self::PINNED_TOOL_MISSING => sprintf('pinned tool [%s] is no longer advertised', $this->tool),
            self::UNPINNED_TOOL => sprintf('tool [%s] is advertised but not pinned', $this->tool),
            default => 'the server has no pinned tool contracts at all',
        };
    }

    /**
     * @return array{kind: string, tool: string, expected: string|null, actual: string|null}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'tool' => $this->tool,
            'expected' => $this->expected,
            'actual' => $this->actual,
        ];
    }
}
