<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Structural guarantee, checked from F-PR1 onward per this program's
 * standing rule ("all with zero real network in CI", Macro F acceptance):
 * no test file may construct `AnthropicDriver` without an explicit
 * `transport:` named argument — omitting it falls back to the real
 * `file_get_contents()`-based transport, which would hit the network.
 *
 * Token-based (via `token_get_all()`), not regex: a regex over the raw
 * source text is fooled by nested parentheses in the argument list (e.g. a
 * schema array literal), fully-qualified class references
 * (`new \Padosoft\LaravelFlowAI\Llm\AnthropicDriver(...)`), and arbitrary
 * whitespace/comment placement — a real gap flagged in round-1 review.
 */
final class NoNetworkCallsInTestSuiteTest extends TestCase
{
    public function test_no_test_constructs_anthropic_driver_without_a_fake_transport(): void
    {
        $testsDir = dirname(__DIR__);
        $violations = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if ($contents === false) {
                self::fail("Could not read {$file->getPathname()} while sweeping for network-unsafe test setup.");
            }

            foreach (self::anthropicDriverConstructions($contents) as $hasTransportArgument) {
                if (! $hasTransportArgument) {
                    $violations[] = $file->getPathname();
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            'These test files construct AnthropicDriver without an explicit fake transport, risking a real network call: '.implode(', ', $violations),
        );
    }

    /**
     * Tokenizes $source and yields one bool per `new [\...]AnthropicDriver(...)`
     * construction found: true if its top-level argument list contains a
     * `transport:` named argument, false otherwise.
     *
     * @return list<bool>
     */
    private static function anthropicDriverConstructions(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $results = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_NEW) {
                continue;
            }

            [$className, $j] = self::readClassNameReference($tokens, $i + 1);

            if (! str_ends_with($className, 'AnthropicDriver')) {
                continue;
            }

            if (($tokens[$j] ?? null) !== '(') {
                continue; // e.g. a bare class-string reference, not a call
            }

            $results[] = self::argumentListHasTransport($tokens, $j);
        }

        return $results;
    }

    /**
     * @param  list<mixed>  $tokens
     * @return array{0: string, 1: int} the accumulated class-name text and the index of the first non-name token after it
     */
    private static function readClassNameReference(array $tokens, int $start): array
    {
        $className = '';
        $j = $start;
        $count = count($tokens);

        while ($j < $count) {
            $t = $tokens[$j];

            if (is_array($t) && in_array($t[0], [T_STRING, T_NS_SEPARATOR], true)) {
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

        return [$className, $j];
    }

    /**
     * Walks the balanced parenthesis span starting at $openParenIndex
     * (which must point at the opening `(`) and reports whether a
     * top-level (depth 1) `transport` identifier is immediately followed
     * (modulo whitespace/comments) by a `:` — a PHP 8 named argument.
     *
     * @param  list<mixed>  $tokens
     */
    private static function argumentListHasTransport(array $tokens, int $openParenIndex): bool
    {
        $count = count($tokens);
        $depth = 0;
        $hasTransport = false;

        for ($k = $openParenIndex; $k < $count; $k++) {
            $t = $tokens[$k];

            if ($t === '(') {
                $depth++;

                continue;
            }

            if ($t === ')') {
                $depth--;

                if ($depth === 0) {
                    break;
                }

                continue;
            }

            if ($depth === 1 && is_array($t) && $t[0] === T_STRING && $t[1] === 'transport') {
                $next = $k + 1;

                while ($next < $count && is_array($tokens[$next]) && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $next++;
                }

                if (($tokens[$next] ?? null) === ':') {
                    $hasTransport = true;
                }
            }
        }

        return $hasTransport;
    }
}
