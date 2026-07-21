<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * `value in test` / `value in (test1, test2)` — the left value is matched
 * against positive unary tests.
 *
 * @see UnaryTests
 *
 * @internal
 */
final class InExpression implements Node
{
    /**
     * @param list<Node> $tests positive unary tests
     */
    public function __construct(
        public readonly Node $value,
        public readonly array $tests,
    ) {
    }
}
