<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Builder;

use InvalidArgumentException;
use Padosoft\LaravelFlowAI\Builder\FlowBuilderResult;
use PHPUnit\Framework\TestCase;

final class FlowBuilderResultTest extends TestCase
{
    public function test_failed_rejects_an_empty_errors_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FlowBuilderResult::failed([]);
    }

    public function test_failed_accepts_a_non_empty_errors_list(): void
    {
        $result = FlowBuilderResult::failed(['something went wrong']);

        $this->assertFalse($result->success);
        $this->assertNull($result->graph);
        $this->assertSame(['something went wrong'], $result->errors);
    }
}
