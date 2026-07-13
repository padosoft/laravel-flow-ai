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
                    // Keyed by pathname: a file with SEVERAL offending
                    // constructions must still be reported once, not once
                    // per construction — the fix is "look at this file", the
                    // same action regardless of how many violations it has.
                    $violations[$file->getPathname()] = true;
                }
            }
        }

        $violatingFiles = array_keys($violations);

        self::assertSame(
            [],
            $violatingFiles,
            'These test files construct AnthropicDriver without an explicit fake transport, risking a real network call: '.implode(', ', $violatingFiles),
        );
    }

    /**
     * Pins the tokenizer's coverage of every class-reference FORM PHP
     * actually produces for a `new` expression, per PHP 8's tokenization
     * rules (verified directly against this package's installed PHP): an
     * unqualified name (`T_STRING`), a namespace-qualified name
     * (`T_NAME_QUALIFIED`), a fully-qualified name (`T_NAME_FULLY_QUALIFIED`),
     * and a `namespace\`-relative name (`T_NAME_RELATIVE`) — round-2 review
     * caught the fully-qualified form being missed entirely by an earlier
     * version of this sweep.
     */
    public function test_sweep_recognizes_every_class_reference_form(): void
    {
        $withTransport = <<<'PHP'
            <?php
            new AnthropicDriver(apiKey: 'k', transport: $fake);
            new Llm\AnthropicDriver(apiKey: 'k', transport: $fake);
            new \Padosoft\LaravelFlowAI\Llm\AnthropicDriver(apiKey: 'k', transport: $fake);
            new namespace\AnthropicDriver(apiKey: 'k', transport: $fake);
            PHP;

        self::assertSame([true, true, true, true], self::anthropicDriverConstructions($withTransport));

        $withoutTransport = <<<'PHP'
            <?php
            new AnthropicDriver(apiKey: 'k');
            new Llm\AnthropicDriver(apiKey: 'k');
            new \Padosoft\LaravelFlowAI\Llm\AnthropicDriver(apiKey: 'k');
            new namespace\AnthropicDriver(apiKey: 'k');
            PHP;

        self::assertSame([false, false, false, false], self::anthropicDriverConstructions($withoutTransport));
    }

    public function test_sweep_is_not_confused_by_nested_parentheses_in_arguments(): void
    {
        $source = <<<'PHP'
            <?php
            new AnthropicDriver(apiKey: 'k', transport: fn (string $u, array $h, string $b, int $t): array => ['status_code' => 200, 'body' => '', 'error' => '']);
            PHP;

        self::assertSame([true], self::anthropicDriverConstructions($source));
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

            // PHP 8+ tokenizes a qualified/fully-qualified/namespace-relative
            // class reference (Llm\AnthropicDriver, \Foo\AnthropicDriver,
            // namespace\AnthropicDriver) as ONE T_NAME_* token carrying the
            // whole backslash-joined string — not a T_STRING/T_NS_SEPARATOR
            // sequence, which only occurs for a bare unqualified name
            // (verified against this package's actual PHP 8.3-8.5 matrix via
            // token_get_all(); a reviewer caught the original version of
            // this sweep missing the FQN form entirely).
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
