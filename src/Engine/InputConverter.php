<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Engine;

use Brick\Math\BigNumber;
use OpenWerk\DecisionModelAndNotation\Exception\InvalidInputException;
use OpenWerk\DecisionModelAndNotation\Feel\Value\DaysTimeDuration;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDate;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDateTime;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelTime;
use OpenWerk\DecisionModelAndNotation\Feel\Value\YearsMonthsDuration;
use OpenWerk\DecisionModelAndNotation\Feel\Value\ZoneKind;

/**
 * Converts caller-supplied PHP values into FEEL runtime values.
 *
 * Unconvertible values throw {@see InvalidInputException} — that is an API
 * misuse detected before evaluation starts, not an evaluation error.
 *
 * @internal
 */
final class InputConverter
{
    private function __construct()
    {
    }

    public static function toFeel(mixed $value): mixed
    {
        if ($value === null || \is_bool($value) || \is_string($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return FeelNumber::of($value);
        }

        if (\is_float($value)) {
            if (!\is_finite($value)) {
                throw new InvalidInputException('cannot convert a non-finite float to a FEEL number');
            }

            return FeelNumber::of($value);
        }

        if (
            $value instanceof FeelNumber
            || $value instanceof FeelDate
            || $value instanceof FeelTime
            || $value instanceof FeelDateTime
            || $value instanceof YearsMonthsDuration
            || $value instanceof DaysTimeDuration
        ) {
            return $value;
        }

        if ($value instanceof BigNumber) {
            return FeelNumber::of($value);
        }

        if ($value instanceof \DateTimeInterface) {
            $dateTime = \DateTimeImmutable::createFromInterface($value);
            $kind = str_contains($dateTime->getTimezone()->getName(), '/') ? ZoneKind::Zone : ZoneKind::Offset;

            return new FeelDateTime($dateTime, $kind);
        }

        if ($value instanceof \DateInterval) {
            return self::fromDateInterval($value);
        }

        if (\is_array($value)) {
            $converted = [];

            foreach ($value as $key => $element) {
                $converted[$key] = self::toFeel($element);
            }

            return $converted;
        }

        throw new InvalidInputException(
            sprintf('cannot convert %s to a FEEL value', get_debug_type($value)),
        );
    }

    private static function fromDateInterval(\DateInterval $interval): YearsMonthsDuration|DaysTimeDuration
    {
        $hasYearsMonths = $interval->y !== 0 || $interval->m !== 0;
        $hasDaysTime = $interval->d !== 0 || $interval->h !== 0 || $interval->i !== 0
            || $interval->s !== 0 || $interval->f !== 0.0;

        if ($hasYearsMonths && $hasDaysTime) {
            throw new InvalidInputException(
                'a DateInterval mixing years/months with days/time has no FEEL duration equivalent',
            );
        }

        $sign = $interval->invert === 1 ? -1 : 1;

        if ($hasYearsMonths) {
            return new YearsMonthsDuration($sign * ($interval->y * 12 + $interval->m));
        }

        $micros = ($interval->d * 86_400 + $interval->h * 3_600 + $interval->i * 60 + $interval->s) * 1_000_000
            + (int) round($interval->f * 1_000_000);

        return DaysTimeDuration::ofMicros($sign * $micros);
    }
}
