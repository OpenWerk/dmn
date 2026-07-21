<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * Property access on a context (`applicant.age`) or a projection over a list
 * of contexts (`orders.amount`).
 *
 * @internal
 */
final class PathExpression implements Node
{
    public function __construct(
        public readonly Node $base,
        public readonly string $property,
    ) {
    }
}
