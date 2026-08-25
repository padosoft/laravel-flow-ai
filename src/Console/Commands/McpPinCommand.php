<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Console\Commands;

use Illuminate\Console\Command;
use JsonException;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpConnectionException;
use Padosoft\LaravelFlowAI\Mcp\McpClient;
use Padosoft\LaravelFlowAI\Mcp\Pinning\PinRegistry;
use Padosoft\LaravelFlowAI\Mcp\Pinning\PinViolation;
use Padosoft\LaravelFlowAI\Mcp\Pinning\ToolContract;
use Padosoft\LaravelFlowAI\Mcp\Pinning\ToolPins;
use Padosoft\LaravelFlowAI\Mcp\Transport\McpTransportFactory;

/**
 * The approval step, and its CI counterpart.
 *
 * Pinning only works if pinning is easy: a digest nobody can compute by
 * hand is a control nobody turns on. This command spawns the server once,
 * reads what it advertises, and prints the exact config block — including
 * the server id, which is the part most likely to be mistyped.
 *
 * `--verify` inverts it into a gate: compare a LIVE server against the pins
 * already in config and exit non-zero on drift. That is the check a
 * scheduled CI job wants, because the alternative — finding out when a
 * production run fails closed — is finding out late.
 *
 * @internal
 */
final class McpPinCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'flow:mcp-pin
        {server : The MCP server command, e.g. npx — `command` is reserved by the console application itself}
        {args?* : Arguments passed to the command}
        {--verify : Compare the live server against the configured pins and exit non-zero on drift}
        {--json : Print the pin block as JSON instead of a PHP config snippet}';

    /**
     * @var string
     */
    protected $description = 'Read an MCP server\'s advertised tool contracts and print their pins (or, with --verify, check a live server against the configured pins).';

    public function handle(McpTransportFactory $transports, PinRegistry $registry): int
    {
        $server = $this->argument('server');
        $command = is_string($server) ? $server : '';
        /** @var list<string> $args */
        $args = array_values(array_filter((array) $this->argument('args'), 'is_string'));
        $serverId = PinRegistry::serverId($command, $args);

        // Deliberately built WITHOUT pins: this command is how a pin comes
        // to exist, so verifying against one here would be circular — and
        // with --verify the comparison is made below, where the result can
        // be reported rather than thrown.
        $client = new McpClient($transports->stdio($command, $args));

        try {
            $tools = $client->listTools();
        } catch (McpConnectionException $e) {
            $this->error("Could not reach MCP server [{$serverId}]: ".$e->getMessage());

            return self::FAILURE;
        } finally {
            $client->close();
        }

        try {
            $pins = self::pinsFor($tools);
        } catch (JsonException $e) {
            $this->error("MCP server [{$serverId}] advertises a tool contract that cannot be canonicalized: ".$e->getMessage());

            return self::FAILURE;
        }

        $configured = $registry->pinnedServers()[$serverId] ?? [];

        if ($this->option('verify') === true) {
            return $this->verify($serverId, $configured, $tools);
        }

        $this->reportDrift($configured, $tools);

        return $this->emit($serverId, $pins);
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, string>
     *
     * @throws JsonException
     */
    private static function pinsFor(array $tools): array
    {
        $pins = [];

        foreach ($tools as $tool) {
            $contract = ToolContract::fromDiscovered($tool);

            if ($contract->name === '') {
                continue;
            }

            $pins[$contract->name] = $contract->digest();
        }

        ksort($pins);

        return $pins;
    }

    /**
     * @param  array<string, string>  $configured
     * @param  list<array<string, mixed>>  $tools
     */
    private function verify(string $serverId, array $configured, array $tools): int
    {
        if ($configured === []) {
            $this->error("No pins configured for MCP server [{$serverId}] — run this command without --verify to generate them.");

            return self::FAILURE;
        }

        $violations = (new ToolPins($serverId, $configured, ToolPins::MODE_WARN))->violations($tools);

        if ($violations === []) {
            $this->info(sprintf('MCP server [%s] matches all %d pinned tool contract(s).', $serverId, count($configured)));

            return self::SUCCESS;
        }

        $this->error(sprintf('MCP server [%s] drifted from its pins:', $serverId));

        foreach ($violations as $violation) {
            $this->line('  - '.$violation->describe());
        }

        return self::FAILURE;
    }

    /**
     * Shown when RE-pinning a server that already has pins: the moment a
     * digest changes is the moment a human is supposed to look at what
     * changed, and printing a fresh block without saying so would turn
     * "approve this" into "rubber-stamp this".
     *
     * @param  array<string, string>  $configured
     * @param  list<array<string, mixed>>  $tools
     */
    private function reportDrift(array $configured, array $tools): void
    {
        if ($configured === []) {
            return;
        }

        $violations = (new ToolPins('', $configured, ToolPins::MODE_WARN))->violations($tools);

        if ($violations === []) {
            $this->info('This server already has pins, and they still match. The block below is identical to what is configured.');

            return;
        }

        $this->warn('This server already has pins, and they no longer match. Review each change before pasting the block below:');

        foreach ($violations as $violation) {
            $this->line('  - '.$violation->describe());
        }

        if (array_filter($violations, static fn (PinViolation $v): bool => $v->kind === PinViolation::CONTRACT_CHANGED) !== []) {
            $this->warn('A changed contract means the server rewrote a description, schema or annotation the model reads. Read the new text before approving it.');
        }

        $this->newLine();
    }

    /**
     * @param  array<string, string>  $pins
     */
    private function emit(string $serverId, array $pins): int
    {
        if ($pins === []) {
            $this->warn("MCP server [{$serverId}] advertises no tools; there is nothing to pin.");

            return self::SUCCESS;
        }

        if ($this->option('json') === true) {
            try {
                $this->line(json_encode([$serverId => $pins], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } catch (JsonException $e) {
                $this->error('Could not encode the pin block: '.$e->getMessage());

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $this->info(sprintf('%d tool contract(s) read from [%s].', count($pins), $serverId));
        $this->newLine();
        $this->line('Add to config/laravel-flow-ai.php, under mcp.pinning.servers:');
        $this->newLine();
        $this->line(sprintf("    '%s' => [", addslashes($serverId)));

        foreach ($pins as $name => $digest) {
            $this->line(sprintf("        '%s' => '%s',", addslashes($name), $digest));
        }

        $this->line('    ],');

        return self::SUCCESS;
    }
}
