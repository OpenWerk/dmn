<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Value;

/**
 * Proleptic Gregorian calendar arithmetic on plain integers, valid for any
 * year (including negative years, which PHP's calendar functions reject).
 *
 * The day-count conversions implement Howard Hinnant's civil-days algorithms.
 *
 * @internal
 */
final class CalendarUtil
{
    private const array DAYS_IN_MONTH = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    private function __construct()
    {
    }

    public static function isLeapYear(int $year): bool
    {
        return $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);
    }

    public static function daysInMonth(int $year, int $month): int
    {
        if ($month === 2 && self::isLeapYear($year)) {
            return 29;
        }

        return self::DAYS_IN_MONTH[$month - 1];
    }

    /**
     * Days since 1970-01-01 for a civil date.
     */
    public static function daysFromCivil(int $year, int $month, int $day): int
    {
        $year -= $month <= 2 ? 1 : 0;
        $era = intdiv($year >= 0 ? $year : $year - 399, 400);
        $yearOfEra = $year - $era * 400;
        $dayOfYear = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1;
        $dayOfEra = $yearOfEra * 365 + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + $dayOfYear;

        return $era * 146_097 + $dayOfEra - 719_468;
    }

    /**
     * Civil date for a number of days since 1970-01-01.
     *
     * @return array{int, int, int} [year, month, day]
     */
    public static function civilFromDays(int $days): array
    {
        $days += 719_468;
        $era = intdiv($days >= 0 ? $days : $days - 146_096, 146_097);
        $dayOfEra = $days - $era * 146_097;
        $yearOfEra = intdiv(
            $dayOfEra - intdiv($dayOfEra, 1_460) + intdiv($dayOfEra, 36_524) - intdiv($dayOfEra, 146_096),
            365
        );
        $year = $yearOfEra + $era * 400;
        $dayOfYear = $dayOfEra - (365 * $yearOfEra + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100));
        $monthPrime = intdiv(5 * $dayOfYear + 2, 153);
        $day = $dayOfYear - intdiv(153 * $monthPrime + 2, 5) + 1;
        $month = $monthPrime + ($monthPrime < 10 ? 3 : -9);
        $year += $month <= 2 ? 1 : 0;

        return [$year, $month, $day];
    }

    /**
     * Adds months to a civil date, clamping the day of month to the target
     * month's length (2027-01-31 + 1 month = 2027-02-28), as required by the
     * FEEL temporal arithmetic rules.
     *
     * @return array{int, int, int} [year, month, day]
     */
    public static function addMonthsClamped(int $year, int $month, int $day, int $months): array
    {
        $zeroBased = $year * 12 + ($month - 1) + $months;
        $newYear = intdiv($zeroBased, 12);
        $remainder = $zeroBased % 12;

        if ($remainder < 0) {
            $newYear--;
            $remainder += 12;
        }

        $newMonth = $remainder + 1;

        return [$newYear, $newMonth, min($day, self::daysInMonth($newYear, $newMonth))];
    }
}
