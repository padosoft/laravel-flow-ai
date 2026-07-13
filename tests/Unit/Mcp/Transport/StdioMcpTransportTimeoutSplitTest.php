<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Mcp\Transport;

use Padosoft\LaravelFlowAI\Mcp\Transport\StdioMcpTransport;
use Padosoft\LaravelFlowAI\Tests\Integration\StdioMcpTransportIntegrationTest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure unit coverage for `StdioMcpTransport::splitTimeout()` — the private
 * static helper that turns a remaining-time budget into
 * `stream_set_timeout()`'s whole-seconds + microseconds pair. Exercised via
 * reflection so this stays a fast, no-subprocess unit test (the transport
 * itself can only be constructed/driven through a real child process, see
 * {@see StdioMcpTransportIntegrationTest}).
 *
 * Regression test for a round-2 review finding: `round()` on the fractional
 * part can hit exactly `1_000_000`, out of `stream_set_timeout()`'s valid
 * `[0, 999999]` microseconds range.
 */
final class StdioMcpTransportTimeoutSplitTest extends TestCase
{
    /**
     * @return array{0: int, 1: int}
     */
    private function splitTimeout(float $remaining): array
    {
        $method = (new ReflectionClass(StdioMcpTransport::class))->getMethod('splitTimeout');

        /** @var array{0: int, 1: int} $result */
        $result = $method->invoke(null, $remaining);

        return $result;
    }

    public function test_fractional_part_near_one_never_overflows_microseconds(): void
    {
        // 0.1 - floor(0.1) = 0.09999999999999998 in IEEE-754 float math, so
        // 3.0999999999999996 exercises the exact "fractional part very close
        // to a whole number" shape that made round() unsafe.
        [$seconds, $microseconds] = $this->splitTimeout(3.0999999999999996);

        $this->assertSame(3, $seconds);
        $this->assertLessThan(1_000_000, $microseconds, 'microseconds must stay in the valid stream_set_timeout() range');
        $this->assertGreaterThanOrEqual(0, $microseconds);
    }

    public function test_exact_whole_number_splits_to_zero_microseconds(): void
    {
        [$seconds, $microseconds] = $this->splitTimeout(5.0);

        $this->assertSame(5, $seconds);
        $this->assertSame(0, $microseconds);
    }

    public function test_small_fractional_remaining_does_not_round_up_to_a_full_second(): void
    {
        // The seconds half must floor, not round/ceil: 0.9s remaining must
        // stay "0 seconds + ~900ms", never jump to a full 1s read timeout
        // (which would let a single read overrun the overall deadline).
        [$seconds, $microseconds] = $this->splitTimeout(0.9);

        $this->assertSame(0, $seconds);
        $this->assertGreaterThan(890_000, $microseconds);
        $this->assertLessThan(1_000_000, $microseconds);
    }

    public function test_split_never_exceeds_the_original_budget(): void
    {
        foreach ([0.1, 1.0, 1.9999999, 2.5, 10.123456, 0.0000001] as $remaining) {
            [$seconds, $microseconds] = $this->splitTimeout($remaining);

            $this->assertGreaterThanOrEqual(0, $seconds);
            $this->assertGreaterThanOrEqual(0, $microseconds);
            $this->assertLessThan(1_000_000, $microseconds, "remaining={$remaining}");
            $this->assertLessThanOrEqual($remaining, $seconds + $microseconds / 1_000_000, "split must never exceed the original budget for remaining={$remaining}");
        }
    }
}
