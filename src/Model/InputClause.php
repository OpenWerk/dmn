<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * A decision-table input column: its input expression and optional allowed
 * input values.
 */
final class InputClause
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $label,
        public readonly LiteralExpression $expression,
        public readonly ?string $typeRef,
        public readonly ?UnaryTestsExpression $inputValues = null,
    ) {
    }
}
