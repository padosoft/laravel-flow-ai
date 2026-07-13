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
