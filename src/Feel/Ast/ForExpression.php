<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * `for x in xs, y in ys return expr` — the cartesian iteration yields a
 * list.
 *
 * @internal
 */
final class ForExpression implements Node
{
    /**
     * @param list<IterationContext> $contexts
     */
    public function __construct(
        public readonly array $contexts,
        public readonly Node $return,
    ) {
    }
}
