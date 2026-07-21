<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * A unary comparison test such as `< 25` or `>= applicant.income`.
 *
 * @internal
 */
final class ComparisonTest implements Node
{
    public function __construct(
        public readonly BinaryOp $op,
        public readonly Node $endpoint,
    ) {
    }
}
