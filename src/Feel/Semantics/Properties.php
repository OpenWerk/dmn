<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Semantics;

use OpenWerk\DecisionModelAndNotation\Feel\Value\DaysTimeDuration;
use OpenWerk\DecisionModelAndNotation\Feel\Value\EqualityTest;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDate;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDateTime;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelRange;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelTime;
use OpenWerk\DecisionModelAndNotation\Feel\Value\YearsMonthsDuration;
use OpenWerk\DecisionModelAndNotation\Feel\Value\ZoneKind;

/**
 * The property matrix of the FEEL value types (DMN 1.5 table 65):
 * `date.year`, `time.hour`, `duration.days`, `range.start included`, ...
 *
 * @internal
 */
final class Properties
{
    private function __construct()
    {
    }

    /**
     * Resolves a property; throws {@see FeelError} for unknown properties or
     * unsupported bases.
     */
    public static function of(mixed $value, string $property): mixed
    {
        if ($value instanceof FeelDate) {
            return self::ofDate($value, $property);
        }

        if ($value instanceof FeelTime) {
            return self::ofTime($value, $property);
        }

        if ($value instanceof FeelDateTime) {
            return self::ofDateTime($value, $property);
        }

        if ($value instanceof YearsMonthsDuration) {
            return match ($property) {
                'years' => FeelNumber::of(intdiv($value->months, 12)),
                'months' => FeelNumber::of($value->months % 12),
                default => throw self::unknown('years and months duration', $property),
            };
        }

        if ($value instanceof DaysTimeDuration) {
            return self::ofDaysTimeDuration($value, $property);
        }

        if ($value instanceof FeelRange) {
            return match ($property) {
                'start' => $value->start,
                'end' => $value->end,
                'start included' => $value->startIncluded,
                'end included' => $value->endIncluded,
                default => throw self::unknown('range', $property),
            };
        }

        // `(=10)` is range-kind: the degenerate closed range [10..10].
        if ($value instanceof EqualityTest && !$value->negated) {
            return match ($property) {
                'start', 'end' => $value->operand,
                'start included', 'end included' => true,
                default => throw self::unknown('range', $property),
            };
        }

        throw new FeelError(sprintf(
            'cannot access property %s on %s',
            var_export($property, true),
            Ops::typeName($value),
        ));
    }

    public static function weekday(FeelDate $date): FeelNumber
    {
        // 1970-01-01 was a Thursday (ISO weekday 4).
        $weekday = (($date->epochDay() + 3) % 7 + 7) % 7 + 1;

        return FeelNumber::of($weekday);
    }

    private static function ofDate(FeelDate $date, string $property): mixed
    {
        return match ($property) {
            'year' => FeelNumber::of($date->year),
            'month' => FeelNumber::of($date->month),
            'day' => FeelNumber::of($date->day),
            'weekday' => self::weekday($date),
            default => throw self::unknown('date', $property),
        };
    }

    private static function ofTime(FeelTime $time, string $property): mixed
    {
        return match ($property) {
            'hour' => FeelNumber::of($time->hour),
            'minute' => FeelNumber::of($time->minute),
            'second' => FeelNumber::of($time->second),
            'time offset' => $time->offsetSeconds === null
                ? null
                : DaysTimeDuration::ofSeconds($time->offsetSeconds),
            'timezone' => $time->zoneId,
            default => throw self::unknown('time', $property),
        };
    }

    private static function ofDateTime(FeelDateTime $dateTime, string $property): mixed
    {
        return match ($property) {
            'year', 'month', 'day', 'weekday' => self::ofDate($dateTime->datePart(), $property),
            'hour', 'minute', 'second' => self::ofTime($dateTime->timePart(), $property),
            'time offset' => $dateTime->kind === ZoneKind::Local
                ? null
                : DaysTimeDuration::ofSeconds($dateTime->dateTime->getOffset()),
            'timezone' => $dateTime->kind === ZoneKind::Zone
                ? $dateTime->dateTime->getTimezone()->getName()
                : null,
            default => throw self::unknown('date and time', $property),
        };
    }

    private static function ofDaysTimeDuration(DaysTimeDuration $duration, string $property): mixed
    {
        $totalSeconds = $duration->seconds;

        return match ($property) {
            'days' => FeelNumber::of(intdiv($totalSeconds, 86_400)),
            'hours' => FeelNumber::of(intdiv($totalSeconds % 86_400, 3_600)),
            'minutes' => FeelNumber::of(intdiv($totalSeconds % 3_600, 60)),
            'seconds' => FeelNumber::of($totalSeconds % 60)
                ->plus(FeelNumber::of($duration->micros)->dividedBy(FeelNumber::of(1_000_000))),
            default => throw self::unknown('days and time duration', $property),
        };
    }

    private static function unknown(string $type, string $property): FeelError
    {
        return new FeelError(sprintf('%s has no property %s', $type, var_export($property, true)));
    }
}
