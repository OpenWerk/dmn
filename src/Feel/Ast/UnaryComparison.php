<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * An equality unary test in expression position: `(=10)` or `(!=10)`.
 * Evaluates to a first-class unary test value (DMN 1.5 treats unary tests
 * as range-kind values).
 *
 * @internal
 */
final class UnaryComparison implements Node
{
    public function __construct(
        public readonly bool $negated,
        public readonly Node $operand,
    ) {
    }
}
