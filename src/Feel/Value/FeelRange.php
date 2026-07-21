<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Value;

use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ops;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ternary;

/**
 * The FEEL `range` type: a pair of endpoints with open/closed bounds.
 * Endpoints are comparable FEEL values; null marks an unbounded end.
 */
final class FeelRange
{
    /**
     * A comparison unary test (`< 10`) leaves one endpoint UNDEFINED, which
     * is distinct from a range literal's explicit null endpoint
     * (`(null..10)`): the two never compare equal (TCK 0068).
     */
    public function __construct(
        public readonly bool $startIncluded,
        public readonly mixed $start,
        public readonly mixed $end,
        public readonly bool $endIncluded,
        public readonly bool $startDefined = true,
        public readonly bool $endDefined = true,
    ) {
    }

    /**
     * Whether the value lies within the range (FEEL ternary result).
     */
    public function includes(mixed $value): ?bool
    {
        $lower = true;

        if ($this->start !== null) {
            $comparison = Ops::compare($value, $this->start);

            if ($comparison === null) {
                return null;
            }

            $lower = $this->startIncluded ? $comparison >= 0 : $comparison > 0;
        }

        $upper = true;

        if ($this->end !== null) {
            $comparison = Ops::compare($value, $this->end);

            if ($comparison === null) {
                return null;
            }

            $upper = $this->endIncluded ? $comparison <= 0 : $comparison < 0;
        }

        return Ternary::andAll($lower, $upper);
    }

    public function isEqualTo(self $other): ?bool
    {
        if ($this->startIncluded !== $other->startIncluded || $this->endIncluded !== $other->endIncluded) {
            return false;
        }

        if ($this->startDefined !== $other->startDefined || $this->endDefined !== $other->endDefined) {
            return false;
        }

        return Ternary::andAll(
            Ops::equals($this->start, $other->start),
            Ops::equals($this->end, $other->end),
        );
    }

    /**
     * Requires comparable endpoints (null = unbounded); used by range
     * literals and the range() constructor.
     */
    public static function of(bool $startIncluded, mixed $start, mixed $end, bool $endIncluded): self
    {
        if ($start !== null && $end !== null) {
            try {
                Ops::compare($start, $end);
            } catch (FeelError) {
                throw new FeelError(sprintf(
                    'range endpoints %s and %s are not comparable',
                    Ops::typeName($start),
                    Ops::typeName($end),
                ));
            }
        }

        return new self($startIncluded, $start, $end, $endIncluded);
    }
}
