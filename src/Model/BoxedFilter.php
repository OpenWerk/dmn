<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * A boxed filter expression (DMN 1.4+): selects the items of `in` for which
 * `match` is true (`item` and context members bound per item).
 */
final class BoxedFilter implements Expression
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?Expression $in,
        public readonly ?Expression $match,
    ) {
    }
}
