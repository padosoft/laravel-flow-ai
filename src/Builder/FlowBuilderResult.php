<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Builder;

use InvalidArgumentException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;

/**
 * Typed outcome of {@see FlowBuilderService::build()}: either a graph that
 * ALREADY passed core's `GraphValidator` (never a "maybe valid" draft), or a
 * non-empty list of human-readable reasons it didn't — never a bare boolean,
 * so a caller (a future `flow:suggest`/Studio panel) can show the model
 * exactly what needs to change, the same way `InvalidGraphException::violations()`
 * already does for every other graph-authoring path in this program.
 *
 * @api
 */
final readonly class FlowBuilderResult
{
    /**
     * @param  list<string>  $errors
     */
    private function __construct(
        public bool $success,
        public ?GraphDefinition $graph,
        public array $errors,
    ) {}

    public static function success(GraphDefinition $graph): self
    {
        return new self(true, $graph, []);
    }

    /**
     * @param  list<string>  $errors
     */
    public static function failed(array $errors): self
    {
        if ($errors === []) {
            throw new InvalidArgumentException('FlowBuilderResult::failed() requires at least one concrete error reason.');
        }

        return new self(false, null, $errors);
    }
}
