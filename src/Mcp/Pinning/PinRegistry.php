<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp\Pinning;

use InvalidArgumentException;
use Padosoft\LaravelFlowAI\Console\Commands\McpPinCommand;
use Padosoft\LaravelFlowAI\Guardrails\PolicyEngine;
use Padosoft\LaravelFlowAI\Nodes\BoundedAgentNode;
use Padosoft\LaravelFlowAI\Nodes\McpClientNode;
use Psr\Log\LoggerInterface;

/**
 * The host's pinning configuration, turned into a per-server
 * {@see ToolPins} on demand — one singleton consulted by every node that
 * opens an MCP session ({@see McpClientNode}, {@see BoundedAgentNode}).
 *
 * **Server identity is the command line.** A pin belongs to
 * `"npx -y @modelcontextprotocol/server-filesystem /srv/docs"`, not to
 * "the filesystem server", because that string is what the graph actually
 * spawns and the only identity available before the process exists. It is
 * also the identity {@see PolicyEngine}
 * already uses for the same servers (as `stdio:{command}`), so an operator
 * reads one concept, not two.
 *
 * That makes a typo in a config key silent by construction — the server
 * simply has no pinset, and with `require_pins` off, no pinset means no
 * check. Two things answer this rather than pretending it away: `require_pins`
 * turns "I have never seen this server" into a violation for hosts that want
 * a closed fleet, and {@see McpPinCommand} prints the exact key alongside the
 * digests, so nobody has to type one.
 *
 * @api
 */
final class PinRegistry
{
    /**
     * @param  array<string, array<string, string>>  $servers  server id => (tool name => digest)
     *
     * @throws InvalidArgumentException on an unrecognised mode — a typo in
     *                                  `pinning.mode` must not silently
     *                                  degrade to "off", which is exactly
     *                                  the outcome a security setting can
     *                                  least afford
     */
    public function __construct(
        private readonly string $mode = ToolPins::MODE_OFF,
        private readonly bool $requirePins = false,
        private readonly array $servers = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
        if (! in_array($this->mode, [ToolPins::MODE_OFF, ToolPins::MODE_WARN, ToolPins::MODE_ENFORCE], true)) {
            throw new InvalidArgumentException(
                "laravel-flow-ai.mcp.pinning.mode [{$this->mode}] is not one of: off, warn, enforce."
            );
        }
    }

    /**
     * The identity a pin is keyed by: the command line the graph spawns,
     * with every run of whitespace collapsed to one space.
     *
     * Applied to BOTH sides — the `(command, args)` a node is about to
     * spawn and the config key an operator wrote — so a stray space in
     * either still resolves to the same pinset. Called with a whole
     * command line as `$command` and no args, it normalises that line the
     * same way, which is how config keys are read.
     *
     * @param  list<string>  $args
     */
    public static function serverId(string $command, array $args = []): string
    {
        $parts = [];

        foreach ([$command, ...$args] as $part) {
            foreach (preg_split('/\s+/', trim($part)) ?: [] as $word) {
                if ($word !== '') {
                    $parts[] = $word;
                }
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Null when pinning is off — the caller then opens the session exactly
     * as it did before this feature existed, with no extra round trip.
     *
     * @param  list<string>  $args
     */
    public function forServer(string $command, array $args = []): ?ToolPins
    {
        if ($this->mode === ToolPins::MODE_OFF) {
            return null;
        }

        $serverId = self::serverId($command, $args);

        return new ToolPins(
            serverId: $serverId,
            pins: $this->servers[$serverId] ?? [],
            mode: $this->mode,
            requirePins: $this->requirePins,
            logger: $this->logger,
        );
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function requiresPins(): bool
    {
        return $this->requirePins;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function pinnedServers(): array
    {
        return $this->servers;
    }
}
