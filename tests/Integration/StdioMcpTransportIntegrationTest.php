<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Integration;

use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpConnectionException;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpToolExecutionException;
use Padosoft\LaravelFlowAI\Mcp\McpClient;
use Padosoft\LaravelFlowAI\Mcp\Transport\StdioMcpTransport;
use Padosoft\LaravelFlowAI\Tests\Unit\NoRealMcpSubprocessInTestSuiteTest;
use PHPUnit\Framework\TestCase;

/**
 * The ONE place in this test suite that spawns a REAL child process (a
 * self-contained PHP fixture script, never a network call or an external
 * package) — {@see NoRealMcpSubprocessInTestSuiteTest}
 * excludes this directory specifically so this file is the deliberate,
 * single, well-understood exception to "everywhere else, use the fakes."
 *
 * Exists to close a gap round-1 review found: `StdioMcpTransport`'s actual
 * `proc_open()`/pipe I/O had zero coverage, and a REAL protocol-correctness
 * bug (server notifications interleaved with responses) was only caught by
 * manual review, not a test. The fixture server
 * ({@see fixtureServerCommand()}) deliberately emits a notification line
 * before every response, so this test doubles as the regression test for
 * that exact fix.
 */
final class StdioMcpTransportIntegrationTest extends TestCase
{
    /**
     * @return array{string, list<string>}
     */
    private function fixtureServerCommand(): array
    {
        $phpBinary = PHP_BINARY;
        $script = dirname(__DIR__).'/Fixtures/stdio-mcp-fixture-server.php';

        return [$phpBinary, [$script]];
    }

    public function test_full_round_trip_against_a_real_subprocess_tolerates_interleaved_notifications(): void
    {
        [$command, $args] = $this->fixtureServerCommand();
        $transport = new StdioMcpTransport($command, $args, timeoutSeconds: 5);
        $client = new McpClient($transport);

        try {
            $tools = $client->listTools();
            $this->assertSame([['name' => 'echo', 'inputSchema' => ['type' => 'object']]], $tools);

            $content = $client->callTool('echo', ['message' => 'hello']);
            $this->assertSame([['type' => 'text', 'text' => '{"message":"hello"}']], $content);
        } finally {
            $client->close();
        }
    }

    public function test_injected_env_vars_reach_the_subprocess_merged_over_the_parent_environment(): void
    {
        [$command, $args] = $this->fixtureServerCommand();
        $transport = new StdioMcpTransport($command, $args, timeoutSeconds: 5, env: ['FLOW_DELEGATED_TOKEN' => 'tok-integration']);
        $client = new McpClient($transport);

        try {
            $content = $client->callTool('echo', ['mode' => 'read_env', 'name' => 'FLOW_DELEGATED_TOKEN']);
            $decoded = json_decode($content[0]['text'], true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame('tok-integration', $decoded['value']);
            // MERGED over the parent environment, never a replacement.
            $this->assertTrue($decoded['path_survived']);
        } finally {
            $client->close();
        }
    }

    public function test_tool_is_error_over_a_real_subprocess_throws_the_typed_exception(): void
    {
        [$command, $args] = $this->fixtureServerCommand();
        $transport = new StdioMcpTransport($command, $args, timeoutSeconds: 5);
        $client = new McpClient($transport);

        try {
            $this->expectException(McpToolExecutionException::class);
            $client->callTool('fail', []);
        } finally {
            $client->close();
        }
    }

    public function test_a_blank_line_from_the_server_is_skipped_not_mistaken_for_eof(): void
    {
        [$command, $args] = $this->fixtureServerCommand();
        $transport = new StdioMcpTransport($command, $args, timeoutSeconds: 5);
        $client = new McpClient($transport);

        try {
            $content = $client->callTool('echo', ['mode' => 'blank_line_before_response']);
            $this->assertSame([['type' => 'text', 'text' => '{"mode":"blank_line_before_response"}']], $content);
        } finally {
            $client->close();
        }
    }

    public function test_a_top_level_json_array_response_over_a_real_subprocess_throws_a_connection_exception(): void
    {
        // Regression test: json_decode(..., true) maps both `{}` and a JSON
        // array to a PHP array, so a naive is_array() check on the raw
        // decoded response line would silently accept a malformed JSON
        // array as a valid-looking id-less notification and hang until the
        // overall timeout, instead of failing fast with a clear exception.
        [$command, $args] = $this->fixtureServerCommand();
        $transport = new StdioMcpTransport($command, $args, timeoutSeconds: 5);
        $client = new McpClient($transport);

        try {
            $this->expectException(McpConnectionException::class);
            $client->callTool('echo', ['mode' => 'array_response']);
        } finally {
            $client->close();
        }
    }

    public function test_a_json_array_result_over_a_real_subprocess_throws_a_connection_exception(): void
    {
        // Regression test: distinct from array_response above (the WHOLE
        // line is an array there). Here the envelope is a valid JSON-RPC
        // object but its `result` MEMBER is `[]` — json_decode(..., true)
        // maps that to the same empty PHP array as a legitimate `{}`
        // result, so without an explicit shape check on the ORIGINAL
        // non-associative decode, this would be silently accepted as a
        // successful call.
        [$command, $args] = $this->fixtureServerCommand();
        $transport = new StdioMcpTransport($command, $args, timeoutSeconds: 5);
        $client = new McpClient($transport);

        try {
            $this->expectException(McpConnectionException::class);
            $client->callTool('echo', ['mode' => 'array_result']);
        } finally {
            $client->close();
        }
    }

    public function test_a_malformed_non_object_result_over_a_real_subprocess_throws_a_connection_exception(): void
    {
        [$command, $args] = $this->fixtureServerCommand();
        $transport = new StdioMcpTransport($command, $args, timeoutSeconds: 5);
        $client = new McpClient($transport);

        try {
            $this->expectException(McpConnectionException::class);
            $client->callTool('echo', ['mode' => 'scalar_result']);
        } finally {
            $client->close();
        }
    }

    public function test_a_response_with_neither_result_nor_error_over_a_real_subprocess_throws_a_connection_exception(): void
    {
        [$command, $args] = $this->fixtureServerCommand();
        $transport = new StdioMcpTransport($command, $args, timeoutSeconds: 5);
        $client = new McpClient($transport);

        try {
            $this->expectException(McpConnectionException::class);
            $client->callTool('echo', ['mode' => 'no_result_no_error']);
        } finally {
            $client->close();
        }
    }

    public function test_write_backpressure_from_a_stuck_subprocess_does_not_hang_and_times_out(): void
    {
        // stream_set_blocking() on a proc_open() pipe is a documented no-op
        // on Windows (PHP bug #47918 and long-standing, still-open Windows
        // stream-select/blocking-mode limitations for anonymous pipes) —
        // confirmed empirically while writing this test: on Windows, write()
        // still blocks for the full child lifetime despite the non-blocking
        // fix, because the underlying fwrite() itself never returns early.
        // CI runs ubuntu-latest exclusively (see .github/workflows), so this
        // regression is real and enforced there; it is skipped on Windows
        // rather than asserted with a 30s-tolerant bound that would defeat
        // its own purpose.
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('proc_open() pipes ignore stream_set_blocking() on Windows — this deadline is only enforceable/verifiable on Linux/macOS, where CI runs.');
        }

        // A subprocess that never reads its stdin at all — proves write()'s
        // own bounded deadline (not just readLine()'s) prevents an
        // indefinite hang once the child stops draining the pipe
        // (backpressure). Bypasses McpClient/the fixture script and talks to
        // StdioMcpTransport directly, since only ONE oversized message is
        // needed to force it.
        $transport = new StdioMcpTransport(PHP_BINARY, ['-r', 'sleep(30);'], timeoutSeconds: 1);

        $start = microtime(true);

        try {
            $this->expectException(McpConnectionException::class);
            // Large enough to exceed any OS pipe buffer and force
            // backpressure, since the child process never reads it.
            $transport->request('initialize', ['giant' => str_repeat('x', 20_000_000)]);
        } finally {
            $elapsed = microtime(true) - $start;
            $this->assertLessThan(5.0, $elapsed, 'the write-phase deadline must fire, not hang indefinitely');
            $transport->close();
        }
    }

    public function test_reading_stderr_after_stdout_closes_does_not_hang_when_stderr_stays_open_and_empty(): void
    {
        // Same Windows proc_open()/stream_set_blocking() limitation as
        // test_write_backpressure_from_a_stuck_subprocess_does_not_hang_and_times_out()
        // above — the stderr pipe is also non-blocking only on platforms
        // where PHP honors it.
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('proc_open() pipes ignore stream_set_blocking() on Windows — this deadline is only enforceable/verifiable on Linux/macOS, where CI runs.');
        }

        // Child closes stdout but keeps running (stderr stays open, empty,
        // with its write end held by a still-live process) — proves the
        // stderr diagnostic read in readLine()'s EOF path does not itself
        // block waiting for stderr data/EOF that will never arrive within
        // any reasonable time.
        $transport = new StdioMcpTransport(PHP_BINARY, ['-r', 'fclose(STDOUT); sleep(30);'], timeoutSeconds: 5);

        $start = microtime(true);

        try {
            $this->expectException(McpConnectionException::class);
            $transport->request('initialize', []);
        } finally {
            $elapsed = microtime(true) - $start;
            $this->assertLessThan(3.0, $elapsed, 'reading stderr must not block waiting for data that will never arrive');
            $transport->close();
        }
    }

    public function test_the_overall_request_timeout_fires_despite_continuous_live_notification_traffic(): void
    {
        // The fixture floods notifications for 40 * 50ms = 2s and never
        // answers this request at all — a client with only a PER-READ
        // timeout (the pre-round-2 bug) would never expire, since every
        // notification arrives well within any single read's window and
        // resets it. A client with an OVERALL per-request deadline (the
        // fix) must still throw at roughly its configured 1s budget.
        [$command, $args] = $this->fixtureServerCommand();
        $transport = new StdioMcpTransport($command, $args, timeoutSeconds: 1);
        $client = new McpClient($transport);

        $start = microtime(true);

        try {
            $this->expectException(McpConnectionException::class);
            $client->callTool('echo', ['mode' => 'flood_never_respond']);
        } finally {
            $elapsed = microtime(true) - $start;
            // Generous upper bound (the flood itself runs 2s) — this only
            // needs to prove the client did NOT wait for the full flood or
            // hang, not pin an exact millisecond budget.
            $this->assertLessThan(1.8, $elapsed, 'the overall deadline must fire well before the 2s flood completes');
            $client->close();
        }
    }
}
