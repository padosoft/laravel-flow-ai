<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Integration;

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
}
