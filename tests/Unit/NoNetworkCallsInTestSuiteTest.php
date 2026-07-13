<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Structural guarantee, checked from F-PR1 onward per this program's
 * standing rule ("all with zero real network in CI", Macro F acceptance):
 * no test file may construct `AnthropicDriver` without an explicit second
 * (`$transport`) constructor argument — omitting it falls back to the real
 * `file_get_contents()`-based transport, which would hit the network. This
 * is a structural sweep, not a runtime network-block: it catches the
 * mistake at test-suite-authoring time, before any test actually runs.
 */
final class NoNetworkCallsInTestSuiteTest extends TestCase
{
    public function test_no_test_constructs_anthropic_driver_without_a_fake_transport(): void
    {
        $testsDir = dirname(__DIR__);
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($testsDir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if ($file->getPathname() === __FILE__) {
                continue; // this file's own docblock/strings mention the class name
            }

            $contents = file_get_contents($file->getPathname());

            if ($contents === false) {
                self::fail("Could not read {$file->getPathname()} while sweeping for network-unsafe test setup.");
            }

            if (! str_contains($contents, 'new AnthropicDriver(')) {
                continue;
            }

            // Every constructor call in this test suite passes named
            // arguments (see AnthropicDriverTest) — require the transport:
            // argument to be present on the SAME construction, not merely
            // present somewhere in the file.
            if (preg_match_all('/new AnthropicDriver\((.*?)\);/s', $contents, $matches) !== false) {
                foreach ($matches[1] as $callArgs) {
                    if (! str_contains($callArgs, 'transport:')) {
                        $violations[] = $file->getPathname();
                    }
                }
            }
        }

        self::assertSame([], $violations, 'These test files construct AnthropicDriver without an explicit fake transport, risking a real network call: '.implode(', ', $violations));
    }
}
