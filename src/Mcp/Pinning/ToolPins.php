<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp\Pinning;

use JsonException;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpToolPinMismatchException;
use Padosoft\LaravelFlowAI\Mcp\McpClient;
use Psr\Log\LoggerInterface;

/**
 * The pinned tool contracts for ONE MCP server, already bound to that
 * server's id — what {@see McpClient} consults after `tools/list`.
 *
 * **A pinset is a statement that the catalog is closed.** Pinning `search`
 * and `fetch` does not mean "check those two and ignore the rest": a server
 * that later also advertises `exfiltrate` has changed into something nobody
 * approved, and the model would happily read the new tool's description on
 * the very next prompt. So an unpinned tool on a pinned server is a
 * violation. A server with NO pinset at all is a different question,
 * answered by `require_pins` at the registry level — pinning is opt-in per
 * server, and a host that pins one server must not thereby have every other
 * server break.
 *
 * Two modes, and the difference is only what happens after the comparison:
 *
 *   - `enforce` — throw {@see McpToolPinMismatchException}. The call is
 *     blocked before it happens.
 *   - `warn` — log at warning level and continue. This exists so a host can
 *     turn pinning on across a real fleet and find out what actually drifts
 *     before it starts failing runs; it is a migration setting, not a
 *     destination, and it is stated as such in the config file.
 *
 * A contract that cannot be canonicalized at all (a server sending invalid
 * UTF-8 in a description) does not silently pass: it cannot equal any pin,
 * so it is reported as a mismatch with a placeholder digest. Fail-closed is
 * the only defensible reading of "I could not check this."
 *
 * @api
 */
final class ToolPins
{
    public const MODE_OFF = 'off';

    public const MODE_WARN = 'warn';

    public const MODE_ENFORCE = 'enforce';

    /**
     * Placeholder digest for a contract this process could not canonicalize.
     * Not a real digest, and deliberately not one a server could produce.
     */
    private const UNDIGESTIBLE = 'sha256:<not-encodable>';

    /**
     * @param  array<string, string>  $pins  tool name => expected digest; an EMPTY map means the server has no pinset (see `require_pins`)
     */
    public function __construct(
        public readonly string $serverId,
        private readonly array $pins,
        private readonly string $mode = self::MODE_ENFORCE,
        private readonly bool $requirePins = false,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function isEnforcing(): bool
    {
        return $this->mode === self::MODE_ENFORCE;
    }

    /**
     * @param  list<array<string, mixed>>  $tools  a raw MCP `tools/list` result
     *
     * @throws McpToolPinMismatchException in `enforce` mode when anything drifted
     */
    public function verify(array $tools): void
    {
        $violations = $this->violations($tools);

        if ($violations === []) {
            return;
        }

        if ($this->isEnforcing()) {
            throw new McpToolPinMismatchException($this->serverId, $violations);
        }

        $this->logger?->warning('MCP tool pin mismatch (warn mode — the call was NOT blocked).', [
            'server' => $this->serverId,
            'violations' => array_map(static fn (PinViolation $v): array => $v->toArray(), $violations),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     * @return list<PinViolation>
     */
    public function violations(array $tools): array
    {
        if ($this->pins === []) {
            return $this->requirePins ? [PinViolation::serverNotPinned()] : [];
        }

        $violations = [];
        $seen = [];

        foreach ($tools as $tool) {
            $contract = ToolContract::fromDiscovered($tool);

            if ($contract->name === '') {
                // A nameless entry cannot be matched to a pin either way,
                // and on a closed catalog it is one more thing the server
                // is advertising that nobody approved.
                $violations[] = PinViolation::unpinnedTool('<unnamed>', self::UNDIGESTIBLE);

                continue;
            }

            $seen[$contract->name] = true;
            $actual = $this->digestOf($contract);
            $expected = $this->pins[$contract->name] ?? null;

            if ($expected === null) {
                $violations[] = PinViolation::unpinnedTool($contract->name, $actual);

                continue;
            }

            if (! hash_equals($expected, $actual)) {
                $violations[] = PinViolation::contractChanged($contract->name, $expected, $actual);
            }
        }

        foreach ($this->pins as $name => $expected) {
            if (! isset($seen[$name])) {
                $violations[] = PinViolation::pinnedToolMissing($name, $expected);
            }
        }

        return $violations;
    }

    private function digestOf(ToolContract $contract): string
    {
        try {
            return $contract->digest();
        } catch (JsonException) {
            return self::UNDIGESTIBLE;
        }
    }
}
