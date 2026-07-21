<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * A decision-table input entry: a comma-separated list of positive unary
 * tests (combined with OR), optionally negated with `not(...)`, or the
 * irrelevant marker `-`.
 *
 * @internal
 */
final class UnaryTests implements Node
{
    /**
     * @param list<Node> $tests
     */
    public function __construct(
        public readonly bool $negated,
        public readonly array $tests,
    ) {
    }
}
