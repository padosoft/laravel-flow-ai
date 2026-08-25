<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Console\Commands;

use Illuminate\Console\Command;
use JsonException;
use Padosoft\LaravelFlowAI\Bom\AiBom;

/**
 * @internal
 */
final class AiBomCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'flow:ai-bom
        {--output= : Write the document to this path instead of stdout}
        {--digest : Print only the content digest (timestamp excluded), for a CI drift gate}';

    /**
     * @var string
     */
    protected $description = 'Emit the AI bill of materials: packages, model providers, pinned MCP servers and tool digests, exposed flows, and the guardrail posture bounding them.';

    public function handle(AiBom $bom): int
    {
        try {
            if ($this->option('digest') === true) {
                $this->line($bom->digest());

                return self::SUCCESS;
            }

            $json = json_encode($bom->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            $this->error('The AI-BOM could not be encoded: '.$e->getMessage());

            return self::FAILURE;
        }

        $output = $this->option('output');

        if (! is_string($output) || $output === '') {
            $this->line($json);

            return self::SUCCESS;
        }

        // Suppressed because the failure is REPORTED below: a raw PHP
        // warning on top of the error line tells an operator nothing extra
        // and turns a clean non-zero exit into noise.
        if (@file_put_contents($output, $json.PHP_EOL) === false) {
            $this->error("Could not write the AI-BOM to [{$output}].");

            return self::FAILURE;
        }

        $this->info("AI-BOM written to {$output}.");

        return self::SUCCESS;
    }
}
