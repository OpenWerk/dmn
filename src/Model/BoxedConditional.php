<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * A boxed conditional expression (DMN 1.4+): if / then / else.
 */
final class BoxedConditional implements Expression
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?Expression $if,
        public readonly ?Expression $then,
        public readonly ?Expression $else,
    ) {
    }
}
