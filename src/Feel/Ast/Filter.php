<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * `base[filter]` — a numeric filter selects by 1-based index (negative from
 * the end); a boolean filter selects matching items, binding `item` and the
 * entries of context items.
 *
 * @internal
 */
final class Filter implements Node
{
    public function __construct(
        public readonly Node $base,
        public readonly Node $condition,
    ) {
    }
}
