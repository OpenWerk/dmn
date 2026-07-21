<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Value;

use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ops;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ternary;

/**
 * A first-class equality unary test: `(=10)` or `(!=10)`. Range-kind, but
 * never equal to an interval range ([10..10] != (=10) per TCK 0068).
 *
 * @internal
 */
final class EqualityTest
{
    public function __construct(
        public readonly bool $negated,
        public readonly mixed $operand,
    ) {
    }

    public function includes(mixed $value): ?bool
    {
        $equal = Ops::equals($value, $this->operand);

        return $this->negated ? Ternary::negate($equal) : $equal;
    }

    public function isEqualTo(self $other): ?bool
    {
        if ($this->negated !== $other->negated) {
            return false;
        }

        return Ops::equals($this->operand, $other->operand);
    }
}
