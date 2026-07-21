<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * A range test such as `[18..65)`, `(0..100]` or `]0..1[`.
 *
 * @internal
 */
final class IntervalTest implements Node
{
    public function __construct(
        public readonly bool $startClosed,
        public readonly Node $start,
        public readonly Node $end,
        public readonly bool $endClosed,
    ) {
    }
}
