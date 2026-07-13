<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit;

use FilesystemIterator;
use Padosoft\LaravelFlowAI\Tests\Integration\StdioMcpTransportIntegrationTest;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Structural guarantee, same posture as {@see NoNetworkCallsInTestSuiteTest}
 * for the LLM path: no test file may construct `StdioMcpTransport` or
 * `StdioMcpTransportFactory` — both spawn a REAL child process
 * (`proc_open()`) — the MCP client test suite exclusively exercises
 * `FakeMcpTransport(Factory)`.
 *
 * ONE deliberate, whitelisted exception: `tests/Integration/` — a single,
 * well-understood real-subprocess test
 * ({@see StdioMcpTransportIntegrationTest})
 * spawning only a self-contained fixture PHP script (never the network, never
 * an external package), added specifically to exercise
 * `StdioMcpTransport`'s actual pipe I/O — this sweep's own reason to exist is
 * to keep that a SINGLE, intentional exception, not an accidental habit.
 */
final class NoRealMcpSubprocessInTestSuiteTest extends TestCase
{
    private const FORBIDDEN_CLASSES = ['StdioMcpTransport', 'StdioMcpTransportFactory'];

    private const EXEMPT_DIRECTORY = 'Integration';

    public function test_no_test_constructs_a_real_stdio_mcp_transport(): void
    {
        $testsDir = dirname(__DIR__);
        $violations = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($testsDir) + 1);

            if (str_starts_with($relativePath, self::EXEMPT_DIRECTORY.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if ($contents === false) {
                self::fail("Could not read {$file->getPathname()} while sweeping for real-subprocess test setup.");
            }

            if (self::constructsForbiddenClass($contents)) {
                $violations[] = $file->getPathname();
            }
        }

        self::assertSame(
            [],
            $violations,
            'These test files construct a real StdioMcpTransport(Factory), risking a real subprocess spawn: '.implode(', ', $violations),
        );
    }

    private static function constructsForbiddenClass(string $source): bool
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_NEW) {
                continue;
            }

            $className = self::readClassNameReference($tokens, $i + 1);

            foreach (self::FORBIDDEN_CLASSES as $forbidden) {
                if (str_ends_with($className, $forbidden)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $tokens
     */
    private static function readClassNameReference(array $tokens, int $start): string
    {
        $className = '';
        $j = $start;
        $count = count($tokens);

        while ($j < $count) {
            $t = $tokens[$j];

            if (is_array($t) && in_array($t[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                $className .= $t[1];
                $j++;

                continue;
            }

            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $j++;

                continue;
            }

            break;
        }

        return $className;
    }
}
