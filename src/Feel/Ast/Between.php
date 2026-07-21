<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * `value between lower and upper` (inclusive on both ends).
 *
 * @internal
 */
final class Between implements Node
{
    public function __construct(
        public readonly Node $value,
        public readonly Node $lower,
        public readonly Node $upper,
    ) {
    }
}
