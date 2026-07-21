<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * A unary-comparison range expression (`< 10`, `>= date("2027-01-01")`) —
 * an open-ended range value (DMN 1.4+).
 *
 * @internal
 */
final class ComparisonRange implements Node
{
    public function __construct(
        public readonly BinaryOp $op,
        public readonly Node $operand,
    ) {
    }
}
