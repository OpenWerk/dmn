<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * A boxed relation: a shorthand for a list of homogeneous contexts (rows
 * over named columns).
 */
final class BoxedRelation implements Expression
{
    /**
     * @param list<string>                $columns
     * @param list<list<Expression|null>> $rows
     */
    public function __construct(
        public readonly ?string $id,
        public readonly array $columns,
        public readonly array $rows,
    ) {
    }
}
