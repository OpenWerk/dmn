<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Semantics;

use OpenWerk\DecisionModelAndNotation\Feel\Value\DaysTimeDuration;
use OpenWerk\DecisionModelAndNotation\Feel\Value\EmptyContext;
use OpenWerk\DecisionModelAndNotation\Feel\Value\EqualityTest;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDate;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDateTime;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelFunction;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelRange;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelTime;
use OpenWerk\DecisionModelAndNotation\Feel\Value\YearsMonthsDuration;

/**
 * The FEEL operator semantics: which type pairs add, subtract, multiply,
 * divide, compare and equal — and what they yield (DMN 1.5, clause 10.3.2).
 *
 * All methods treat null as absorbing (null in, null out). Type combinations
 * outside the specification's matrix raise {@see FeelError}, which the
 * evaluator converts into null plus a diagnostic.
 *
 * @internal
 */
final class Ops
{
    private const int MICROS_PER_DAY = 86_400_000_000;

    private function __construct()
    {
    }

    public static function add(mixed $a, mixed $b): mixed
    {
        if ($a === null || $b === null) {
            return null;
        }

        if ($a instanceof FeelNumber && $b instanceof FeelNumber) {
            return $a->plus($b);
        }

        // FEEL defines + on strings as concatenation (DMN 1.5, 10.3.2.9).
        if (\is_string($a) && \is_string($b)) {
            return $a . $b;
        }

        if ($a instanceof YearsMonthsDuration && $b instanceof YearsMonthsDuration) {
            return $a->plus($b);
        }

        if ($a instanceof DaysTimeDuration && $b instanceof DaysTimeDuration) {
            return $a->plus($b);
        }

        if ($a instanceof FeelDate && $b instanceof YearsMonthsDuration) {
            return $a->addMonths($b->months);
        }

        if ($a instanceof YearsMonthsDuration && $b instanceof FeelDate) {
            return $b->addMonths($a->months);
        }

        if ($a instanceof FeelDate && $b instanceof DaysTimeDuration) {
            return $a->addDays(self::wholeDays($b->totalMicros()));
        }

        if ($a instanceof DaysTimeDuration && $b instanceof FeelDate) {
            return $b->addDays(self::wholeDays($a->totalMicros()));
        }

        if ($a instanceof FeelDateTime && $b instanceof YearsMonthsDuration) {
            return $a->plusMonths($b->months);
        }

        if ($a instanceof YearsMonthsDuration && $b instanceof FeelDateTime) {
            return $b->plusMonths($a->months);
        }

        if ($a instanceof FeelDateTime && $b instanceof DaysTimeDuration) {
            return $a->plus($b);
        }

        if ($a instanceof DaysTimeDuration && $b instanceof FeelDateTime) {
            return $b->plus($a);
        }

        if ($a instanceof FeelTime && $b instanceof DaysTimeDuration) {
            return $a->plus($b);
        }

        if ($a instanceof DaysTimeDuration && $b instanceof FeelTime) {
            return $b->plus($a);
        }

        throw self::unsupported('add', $a, $b);
    }

    public static function subtract(mixed $a, mixed $b): mixed
    {
        if ($a === null || $b === null) {
            return null;
        }

        if ($a instanceof FeelNumber && $b instanceof FeelNumber) {
            return $a->minus($b);
        }

        if ($a instanceof YearsMonthsDuration && $b instanceof YearsMonthsDuration) {
            return $a->minus($b);
        }

        if ($a instanceof DaysTimeDuration && $b instanceof DaysTimeDuration) {
            return $a->minus($b);
        }

        if ($a instanceof FeelDate && $b instanceof FeelDate) {
            return $a->minus($b);
        }

        if ($a instanceof FeelDateTime && $b instanceof FeelDateTime) {
            return $a->minus($b);
        }

        // date and `date and time` mix: the date is coerced to midnight UTC
        // (errors when the other side is a local date and time).
        if ($a instanceof FeelDate && $b instanceof FeelDateTime) {
            return self::midnight($a)->minus($b);
        }

        if ($a instanceof FeelDateTime && $b instanceof FeelDate) {
            return $a->minus(self::midnight($b));
        }

        if ($a instanceof FeelTime && $b instanceof FeelTime) {
            return $a->minus($b);
        }

        if ($a instanceof FeelDate && $b instanceof YearsMonthsDuration) {
            return $a->addMonths(-$b->months);
        }

        if ($a instanceof FeelDate && $b instanceof DaysTimeDuration) {
            return $a->addDays(self::wholeDays(-$b->totalMicros()));
        }

        if ($a instanceof FeelDateTime && $b instanceof YearsMonthsDuration) {
            return $a->plusMonths(-$b->months);
        }

        if ($a instanceof FeelDateTime && $b instanceof DaysTimeDuration) {
            return $a->plus($b->negated());
        }

        if ($a instanceof FeelTime && $b instanceof DaysTimeDuration) {
            return $a->plus($b->negated());
        }

        throw self::unsupported('subtract', $a, $b);
    }

    public static function multiply(mixed $a, mixed $b): mixed
    {
        if ($a === null || $b === null) {
            return null;
        }

        if ($a instanceof FeelNumber && $b instanceof FeelNumber) {
            return $a->multipliedBy($b);
        }

        if ($a instanceof YearsMonthsDuration && $b instanceof FeelNumber) {
            return $a->multipliedBy($b);
        }

        if ($a instanceof FeelNumber && $b instanceof YearsMonthsDuration) {
            return $b->multipliedBy($a);
        }

        if ($a instanceof DaysTimeDuration && $b instanceof FeelNumber) {
            return $a->multipliedBy($b);
        }

        if ($a instanceof FeelNumber && $b instanceof DaysTimeDuration) {
            return $b->multipliedBy($a);
        }

        throw self::unsupported('multiply', $a, $b);
    }

    public static function divide(mixed $a, mixed $b): mixed
    {
        if ($a === null || $b === null) {
            return null;
        }

        if ($a instanceof FeelNumber && $b instanceof FeelNumber) {
            return $a->dividedBy($b);
        }

        if ($a instanceof YearsMonthsDuration && $b instanceof FeelNumber) {
            return $a->dividedBy($b);
        }

        if ($a instanceof DaysTimeDuration && $b instanceof FeelNumber) {
            return $a->dividedBy($b);
        }

        if ($a instanceof YearsMonthsDuration && $b instanceof YearsMonthsDuration) {
            return $a->ratio($b);
        }

        if ($a instanceof DaysTimeDuration && $b instanceof DaysTimeDuration) {
            return $a->ratio($b);
        }

        throw self::unsupported('divide', $a, $b);
    }

    public static function power(mixed $a, mixed $b): mixed
    {
        if ($a === null || $b === null) {
            return null;
        }

        if ($a instanceof FeelNumber && $b instanceof FeelNumber) {
            return $a->power($b);
        }

        throw self::unsupported('exponentiate', $a, $b);
    }

    public static function negate(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof FeelNumber) {
            return $value->negated();
        }

        if ($value instanceof YearsMonthsDuration || $value instanceof DaysTimeDuration) {
            return $value->negated();
        }

        throw new FeelError(sprintf('cannot negate %s', self::typeName($value)));
    }

    /**
     * Ordering comparison. Returns null when either operand is null; throws
     * {@see FeelError} for operand kinds the specification defines no
     * ordering for (booleans, lists, contexts, mixed kinds).
     */
    public static function compare(mixed $a, mixed $b): ?int
    {
        if ($a === null || $b === null) {
            return null;
        }

        if ($a instanceof FeelNumber && $b instanceof FeelNumber) {
            return $a->compareTo($b);
        }

        if (\is_string($a) && \is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }

        if ($a instanceof FeelDate && $b instanceof FeelDate) {
            return $a->compareTo($b);
        }

        if ($a instanceof FeelTime && $b instanceof FeelTime) {
            return $a->compareTo($b);
        }

        if ($a instanceof FeelDateTime && $b instanceof FeelDateTime) {
            return $a->compareTo($b);
        }

        if ($a instanceof YearsMonthsDuration && $b instanceof YearsMonthsDuration) {
            return $a->compareTo($b);
        }

        if ($a instanceof DaysTimeDuration && $b instanceof DaysTimeDuration) {
            return $a->compareTo($b);
        }

        throw new FeelError(sprintf(
            'cannot compare %s with %s',
            self::typeName($a),
            self::typeName($b),
        ));
    }

    /**
     * FEEL equality: null = null is true; null against a value is false;
     * values of different kinds are incomparable (null); otherwise value
     * equality — per the DMN TCK's equality semantics.
     */
    public static function equals(mixed $a, mixed $b): ?bool
    {
        if ($a === null && $b === null) {
            return true;
        }

        if ($a === null || $b === null) {
            return false;
        }

        if (\is_bool($a) && \is_bool($b)) {
            return $a === $b;
        }

        if (\is_string($a) && \is_string($b)) {
            return $a === $b;
        }

        if ($a instanceof FeelNumber && $b instanceof FeelNumber) {
            return $a->isEqualTo($b);
        }

        if ($a instanceof FeelDate && $b instanceof FeelDate) {
            return $a->isEqualTo($b);
        }

        if (
            ($a instanceof FeelTime && $b instanceof FeelTime)
            || ($a instanceof FeelDateTime && $b instanceof FeelDateTime)
        ) {
            try {
                return $a->isEqualTo($b);
            } catch (FeelError) {
                // zoned vs local flavors never compare equal
                return false;
            }
        }

        if ($a instanceof YearsMonthsDuration && $b instanceof YearsMonthsDuration) {
            return $a->isEqualTo($b);
        }

        if ($a instanceof DaysTimeDuration && $b instanceof DaysTimeDuration) {
            return $a->isEqualTo($b);
        }

        if ($a instanceof FeelRange && $b instanceof FeelRange) {
            return $a->isEqualTo($b);
        }

        if ($a instanceof EqualityTest && $b instanceof EqualityTest) {
            return $a->isEqualTo($b);
        }

        // Unary tests are range-kind values, but never equal an interval.
        if (
            ($a instanceof EqualityTest && $b instanceof FeelRange)
            || ($a instanceof FeelRange && $b instanceof EqualityTest)
        ) {
            return false;
        }

        if ($a instanceof EmptyContext || $b instanceof EmptyContext) {
            if ($a instanceof EmptyContext && $b instanceof EmptyContext) {
                return true;
            }

            // empty context vs non-empty context: false; vs other kind: null
            return self::isContext($a) && self::isContext($b) ? false : null;
        }

        if (\is_array($a) && \is_array($b)) {
            return self::arrayEquals($a, $b);
        }

        return null;
    }

    public static function isList(mixed $value): bool
    {
        return \is_array($value) && array_is_list($value);
    }

    public static function isContext(mixed $value): bool
    {
        return $value instanceof EmptyContext || (\is_array($value) && !array_is_list($value));
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function contextToArray(mixed $value): array
    {
        if ($value instanceof EmptyContext) {
            return [];
        }

        \assert(\is_array($value));

        return $value;
    }

    public static function typeName(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            \is_bool($value) => 'boolean',
            \is_string($value) => 'string',
            $value instanceof FeelNumber => 'number',
            $value instanceof FeelDate => 'date',
            $value instanceof FeelTime => 'time',
            $value instanceof FeelDateTime => 'date and time',
            $value instanceof YearsMonthsDuration => 'years and months duration',
            $value instanceof DaysTimeDuration => 'days and time duration',
            $value instanceof FeelFunction => 'function',
            $value instanceof FeelRange => 'range',
            $value instanceof EqualityTest => 'range',
            $value instanceof EmptyContext => 'context',
            self::isList($value) => 'list',
            \is_array($value) => 'context',
            default => get_debug_type($value),
        };
    }

    /**
     * @param array<array-key, mixed> $a
     * @param array<array-key, mixed> $b
     */
    private static function arrayEquals(array $a, array $b): ?bool
    {
        $aIsList = array_is_list($a);

        if ($aIsList !== array_is_list($b)) {
            return null;
        }

        if (\count($a) !== \count($b)) {
            return false;
        }

        if (!$aIsList) {
            $aKeys = array_map(strval(...), array_keys($a));
            $bKeys = array_map(strval(...), array_keys($b));
            sort($aKeys);
            sort($bKeys);

            if ($aKeys !== $bKeys) {
                return false;
            }
        }

        $results = [];

        foreach ($a as $key => $value) {
            $results[] = self::equals($value, $b[$key] ?? null);
        }

        return Ternary::andAll(...$results);
    }

    private static function midnight(FeelDate $date): FeelDateTime
    {
        // The specification coerces a date to midnight UTC in mixed
        // date / date-and-time arithmetic.
        return FeelDateTime::fromDateAndTime($date, new FeelTime(0, 0, 0, 0, 0));
    }

    /**
     * Whole days contained in a signed microsecond count, floored — the
     * date +/- days-and-time-duration rule (midnight anchoring).
     */
    private static function wholeDays(int $micros): int
    {
        $days = intdiv($micros, self::MICROS_PER_DAY);

        if ($micros % self::MICROS_PER_DAY !== 0 && $micros < 0) {
            $days--;
        }

        return $days;
    }

    private static function unsupported(string $operation, mixed $a, mixed $b): FeelError
    {
        return new FeelError(sprintf(
            'cannot %s %s and %s',
            $operation,
            self::typeName($a),
            self::typeName($b),
        ));
    }
}
