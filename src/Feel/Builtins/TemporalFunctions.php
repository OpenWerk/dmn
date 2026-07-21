<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Builtins;

use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Properties;
use OpenWerk\DecisionModelAndNotation\Feel\Value\CalendarUtil;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDate;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDateTime;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;
use OpenWerk\DecisionModelAndNotation\Feel\Value\YearsMonthsDuration;

/**
 * Temporal functions beyond the constructors (DMN 1.5, 10.3.4.7).
 *
 * @internal
 */
final class TemporalFunctions
{
    private const array WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    private const array MONTHS = [
        1 => 'January',
        2 => 'February',
        3 => 'March',
        4 => 'April',
        5 => 'May',
        6 => 'June',
        7 => 'July',
        8 => 'August',
        9 => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December',
    ];

    private function __construct()
    {
    }

    public static function register(BuiltinCatalog $catalog): void
    {
        $catalog->register(
            'day of week',
            [[['date'], 1]],
            static function (array $args): mixed {
                $date = self::date($args[0], 'day of week');

                return self::WEEKDAYS[Properties::weekday($date)->toInt()];
            },
        );

        $catalog->register(
            'day of year',
            [[['date'], 1]],
            static function (array $args): mixed {
                $date = self::date($args[0], 'day of year');

                return FeelNumber::of(
                    $date->epochDay() - CalendarUtil::daysFromCivil($date->year, 1, 1) + 1,
                );
            },
        );

        $catalog->register(
            'week of year',
            [[['date'], 1]],
            static function (array $args): mixed {
                $date = self::date($args[0], 'week of year');

                return FeelNumber::of(self::isoWeek($date));
            },
        );

        $catalog->register(
            'month of year',
            [[['date'], 1]],
            static function (array $args): mixed {
                $date = self::date($args[0], 'month of year');

                return self::MONTHS[$date->month];
            },
        );

        $catalog->register(
            'last day of month',
            [[['date'], 1]],
            static function (array $args): mixed {
                $date = self::date($args[0], 'last day of month');

                return new FeelDate($date->year, $date->month, CalendarUtil::daysInMonth($date->year, $date->month));
            },
        );

        $catalog->register(
            'years and months duration',
            [[['from', 'to'], 2]],
            static function (array $args): mixed {
                // Only the date components count: the time of day never
                // trims a whole month (TCK 1121).
                $from = self::datePart($args[0], 'years and months duration', 'from');
                $to = self::datePart($args[1], 'years and months duration', 'to');

                return new YearsMonthsDuration(self::wholeMonthsBetween($from, $to));
            },
        );
    }

    private static function date(mixed $value, string $function): FeelDate
    {
        $value = Args::unwrapSingleton($value);

        if ($value instanceof FeelDate) {
            return $value;
        }

        if ($value instanceof FeelDateTime) {
            return $value->datePart();
        }

        throw Args::typeError($function, 'date', 'a date or date and time', $value);
    }

    private static function datePart(mixed $value, string $function, string $parameter): FeelDate
    {
        $value = Args::unwrapSingleton($value);

        if ($value instanceof FeelDate) {
            return $value;
        }

        if ($value instanceof FeelDateTime) {
            return $value->datePart();
        }

        throw Args::typeError($function, $parameter, 'a date or date and time', $value);
    }

    /**
     * Whole months between two dates, truncated toward zero.
     */
    private static function wholeMonthsBetween(FeelDate $fromDate, FeelDate $toDate): int
    {
        $negative = false;

        if ($toDate->compareTo($fromDate) < 0) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
            $negative = true;
        }

        $months = ($toDate->year - $fromDate->year) * 12 + ($toDate->month - $fromDate->month);

        if ($toDate->day < $fromDate->day) {
            $months--;
        }

        return $negative ? -$months : $months;
    }

    private static function isoWeek(FeelDate $date): int
    {
        $ordinal = $date->epochDay() - CalendarUtil::daysFromCivil($date->year, 1, 1) + 1;
        $weekday = Properties::weekday($date)->toInt();
        $week = intdiv($ordinal - $weekday + 10, 7);

        if ($week < 1) {
            return self::weeksInYear($date->year - 1);
        }

        if ($week > self::weeksInYear($date->year)) {
            return 1;
        }

        return $week;
    }

    private static function weeksInYear(int $year): int
    {
        $p = static fn(int $y): int => (
            ($y + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400)) % 7 + 7
        ) % 7;

        return $p($year) === 4 || $p($year - 1) === 3 ? 53 : 52;
    }
}
