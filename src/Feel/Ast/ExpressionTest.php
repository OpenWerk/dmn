<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * A unary test that compares the input value for equality against the value
 * of an expression — or, when the expression yields a list, tests membership.
 *
 * @internal
 */
final class ExpressionTest implements Node
{
    public function __construct(public readonly Node $expression)
    {
    }
}
