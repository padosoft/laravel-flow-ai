<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Contract;

use Padosoft\LaravelFlowAI\Advisor\Analyzer;
use Padosoft\LaravelFlowAI\Advisor\Finding;
use Padosoft\LaravelFlowAI\Advisor\FlowAdvisor;
use Padosoft\LaravelFlowAI\Advisor\Suggestion;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Pins this package's `@api` Flow Advisor surface — `FlowAdvisor` is the
 * ONLY F-PR whose consumers include a FUTURE macro (Macro E's visual diff
 * UI, which reads `Suggestion`/`Finding`'s shape), per the Macro F plan's
 * own grounding note. Any future breaking change to this shape must update
 * this test in the same commit.
 */
final class FlowAdvisorContractTest extends TestCase
{
    public function test_flow_advisor_public_methods(): void
    {
        $reflection = new ReflectionClass(FlowAdvisor::class);

        self::assertTrue($reflection->hasMethod('suggest'));
        self::assertTrue($reflection->hasMethod('improve'));

        $suggest = $reflection->getMethod('suggest');
        self::assertCount(0, $suggest->getParameters());

        $improve = $reflection->getMethod('improve');
        self::assertCount(1, $improve->getParameters());
        self::assertSame('string', self::typeName($improve->getParameters()[0]->getType()));
    }

    public function test_analyzer_contract_shape(): void
    {
        $reflection = new ReflectionClass(Analyzer::class);
        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('analyze'));

        $method = $reflection->getMethod('analyze');
        self::assertCount(2, $method->getParameters());
        self::assertSame('string', self::typeName($method->getParameters()[0]->getType()));
        self::assertSame('array', self::typeName($method->getParameters()[1]->getType()));
    }

    public function test_finding_shape(): void
    {
        $reflection = new ReflectionClass(Finding::class);

        foreach (['type', 'summary', 'rationale'] as $property) {
            self::assertTrue($reflection->hasProperty($property), $property);
        }

        $finding = new Finding(type: 'failure_hotspot', summary: 'x', rationale: ['a' => 1]);
        self::assertSame('failure_hotspot', $finding->type);
        self::assertSame(['a' => 1], $finding->rationale);
    }

    public function test_suggestion_shape(): void
    {
        $reflection = new ReflectionClass(Suggestion::class);

        foreach (['definitionName', 'draftVersion', 'finding'] as $property) {
            self::assertTrue($reflection->hasProperty($property), $property);
        }

        self::assertTrue($reflection->hasMethod('draftVersionId'));

        $suggestion = new Suggestion(
            definitionName: 'my-flow',
            draftVersion: 3,
            finding: new Finding(type: 'failure_hotspot', summary: 'x', rationale: []),
        );
        self::assertSame('my-flow@3', $suggestion->draftVersionId());
    }

    private static function typeName(?\ReflectionType $type): ?string
    {
        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }
}
