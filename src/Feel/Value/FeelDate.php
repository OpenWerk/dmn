<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Value;

use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;

/**
 * The FEEL `date` type: a calendar date without time or zone.
 */
final class FeelDate implements \Stringable
{
    public function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly int $day,
    ) {
        if (
            $year < -999_999_999 || $year > 999_999_999
            || $month < 1 || $month > 12
            || $day < 1 || $day > CalendarUtil::daysInMonth($year, $month)
        ) {
            throw new FeelError(sprintf('invalid date %d-%02d-%02d', $year, $month, $day));
        }
    }

    public function compareTo(self $other): int
    {
        return [$this->year, $this->month, $this->day] <=> [$other->year, $other->month, $other->day];
    }

    public function isEqualTo(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    /**
     * Days since 1970-01-01.
     */
    public function epochDay(): int
    {
        return CalendarUtil::daysFromCivil($this->year, $this->month, $this->day);
    }

    public static function fromEpochDay(int $days): self
    {
        [$year, $month, $day] = CalendarUtil::civilFromDays($days);

        return new self($year, $month, $day);
    }

    public function addMonths(int $months): self
    {
        [$year, $month, $day] = CalendarUtil::addMonthsClamped($this->year, $this->month, $this->day, $months);

        return new self($year, $month, $day);
    }

    public function addDays(int $days): self
    {
        return self::fromEpochDay($this->epochDay() + $days);
    }

    /**
     * date - date yields a days-and-time duration (whole days).
     */
    public function minus(self $other): DaysTimeDuration
    {
        return DaysTimeDuration::ofSeconds(($this->epochDay() - $other->epochDay()) * 86_400);
    }

    public function __toString(): string
    {
        $year = $this->year < 0
            ? '-' . str_pad((string) -$this->year, 4, '0', STR_PAD_LEFT)
            : str_pad((string) $this->year, 4, '0', STR_PAD_LEFT);

        return sprintf('%s-%02d-%02d', $year, $this->month, $this->day);
    }
}
