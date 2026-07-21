<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * A range expression such as `[1..10]`, `(0..1]` — a first-class FEEL
 * range value.
 *
 * @internal
 */
final class RangeLiteral implements Node
{
    public function __construct(
        public readonly bool $startClosed,
        public readonly Node $start,
        public readonly Node $end,
        public readonly bool $endClosed,
    ) {
    }
}
