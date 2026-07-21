<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * A boxed list expression.
 */
final class BoxedList implements Expression
{
    /**
     * @param list<Expression|null> $items
     */
    public function __construct(
        public readonly ?string $id,
        public readonly array $items,
    ) {
    }
}
