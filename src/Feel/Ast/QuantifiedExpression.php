<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * `some/every x in xs satisfies expr` with FEEL ternary semantics.
 *
 * @internal
 */
final class QuantifiedExpression implements Node
{
    /**
     * @param list<IterationContext> $contexts
     */
    public function __construct(
        public readonly bool $every,
        public readonly array $contexts,
        public readonly Node $satisfies,
    ) {
    }
}
