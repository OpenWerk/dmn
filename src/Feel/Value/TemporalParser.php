<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Value;

use Brick\Math\BigDecimal;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;

/**
 * Parses the lexical forms of the FEEL temporal types, shared by the
 * `date()` / `time()` / `date and time()` / `duration()` constructors and by
 * `@"..."` at-literals.
 *
 * @internal
 */
final class TemporalParser
{
    // Years beyond four digits must not carry leading zeros (XML Schema).
    private const string DATE_PATTERN = '/^(-?(?:[1-9]\d{4,8}|\d{4}))-(\d{2})-(\d{2})$/';

    private const string TIME_PATTERN =
        '/^(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,9}))?(?:(Z)|([+-]\d{2}:\d{2})|@([A-Za-z0-9_+\-\/]+))?$/';

    // The seconds fraction may be empty ("PT0.S"): XML Schema's duSecondFrag
    // is [0-9]+(\.[0-9]*)?S, so a trailing point denotes zero fraction.
    private const string DURATION_PATTERN =
        '/^(-)?P(?=.)(?:(\d{1,10})Y)?(?:(\d{1,10})M)?(?:(\d{1,10})D)?' .
        '(?:T(?=.)(?:(\d{1,10})H)?(?:(\d{1,10})M)?(?:(\d{1,10}(?:\.\d{0,9})?)S)?)?$/';

    private function __construct()
    {
    }

    public static function date(string $text): FeelDate
    {
        if (preg_match(self::DATE_PATTERN, $text, $matches) !== 1) {
            throw new FeelError(sprintf('invalid date literal %s', var_export($text, true)));
        }

        return new FeelDate((int) $matches[1], (int) $matches[2], (int) $matches[3]);
    }

    public static function time(string $text): FeelTime
    {
        if (preg_match(self::TIME_PATTERN, $text, $matches) !== 1) {
            throw new FeelError(sprintf('invalid time literal %s', var_export($text, true)));
        }

        // The lexical fraction carries up to nine digits; microseconds feed
        // arithmetic, the sub-microsecond remainder is kept for round-trips.
        $fraction = $matches[4] ?? '';
        $nanos = $fraction === '' ? 0 : (int) str_pad($fraction, 9, '0');
        $microsecond = intdiv($nanos, 1_000);
        $nanoOfMicro = $nanos % 1_000;

        $offsetSeconds = null;
        $zoneId = null;

        if (isset($matches[5]) && $matches[5] === 'Z') {
            $offsetSeconds = 0;
        } elseif (isset($matches[6]) && $matches[6] !== '') {
            $offsetSeconds = self::parseOffset($matches[6]);
        } elseif (isset($matches[7])) {
            $zoneId = $matches[7];
            $offsetSeconds = self::resolveZoneOffset($zoneId);
        }

        return new FeelTime(
            (int) $matches[1],
            (int) $matches[2],
            (int) $matches[3],
            $microsecond,
            $offsetSeconds,
            $zoneId,
            $nanoOfMicro,
        );
    }

    public static function dateAndTime(string $text): FeelDateTime
    {
        $separator = strpos($text, 'T');

        if ($separator === false) {
            return FeelDateTime::fromDateAndTime(self::date($text), new FeelTime(0, 0, 0));
        }

        $date = self::date(substr($text, 0, $separator));
        $timeText = substr($text, $separator + 1);

        // XML Schema allows 24:00:00, denoting midnight at the end of the day.
        if (preg_match('/^24:00:00(?:\.0+)?($|[+\-Z@])/', $timeText) === 1) {
            $timeText = '00' . substr($timeText, 2);
            $date = $date->addDays(1);
        }

        return FeelDateTime::fromDateAndTime($date, self::time($timeText));
    }

    public static function duration(string $text): YearsMonthsDuration|DaysTimeDuration
    {
        if (preg_match(self::DURATION_PATTERN, $text, $matches) !== 1) {
            throw new FeelError(sprintf('invalid duration literal %s', var_export($text, true)));
        }

        $negative = ($matches[1] ?? '') === '-';
        $years = self::group($matches, 2);
        $months = self::group($matches, 3);
        $days = self::group($matches, 4);
        $hours = self::group($matches, 5);
        $minutes = self::group($matches, 6);
        $seconds = self::group($matches, 7);

        $hasYearsMonths = $years !== null || $months !== null;
        $hasDaysTime = $days !== null || $hours !== null || $minutes !== null || $seconds !== null;

        if ($hasYearsMonths && $hasDaysTime) {
            throw new FeelError(sprintf(
                'invalid duration literal %s: FEEL durations are either years/months or days/time, not both',
                var_export($text, true),
            ));
        }

        if ($hasYearsMonths) {
            $totalMonths = (int) ($years ?? '0') * 12 + (int) ($months ?? '0');

            return new YearsMonthsDuration($negative ? -$totalMonths : $totalMonths);
        }

        $total = BigDecimal::of((int) ($days ?? '0'))->multipliedBy(86_400)
            ->plus(BigDecimal::of((int) ($hours ?? '0'))->multipliedBy(3_600))
            ->plus(BigDecimal::of((int) ($minutes ?? '0'))->multipliedBy(60))
            ->plus(BigDecimal::of(rtrim($seconds ?? '0', '.')));

        if ($negative) {
            $total = $total->negated();
        }

        return DaysTimeDuration::ofDecimalSeconds($total);
    }

    /**
     * Resolves an `@"..."` at-literal into the temporal value its lexical
     * form denotes.
     */
    public static function atLiteral(string $text): FeelDate|FeelTime|FeelDateTime|YearsMonthsDuration|DaysTimeDuration
    {
        if (str_starts_with($text, 'P') || str_starts_with($text, '-P')) {
            return self::duration($text);
        }

        if (str_contains($text, 'T')) {
            return self::dateAndTime($text);
        }

        if (preg_match(self::DATE_PATTERN, $text) === 1) {
            return self::date($text);
        }

        if (str_contains($text, ':')) {
            return self::time($text);
        }

        throw new FeelError(sprintf('invalid at-literal @%s', var_export($text, true)));
    }

    private static function parseOffset(string $offset): int
    {
        $sign = $offset[0] === '-' ? -1 : 1;
        $hours = (int) substr($offset, 1, 2);
        $minutes = (int) substr($offset, 4, 2);

        return $sign * ($hours * 3_600 + $minutes * 60);
    }

    /**
     * Deviation (documented): a time has no date, so zone-id offsets are
     * resolved against 1970-01-01.
     */
    private static function resolveZoneOffset(string $zoneId): int
    {
        try {
            $zone = new \DateTimeZone($zoneId);
        } catch (\Exception) {
            throw new FeelError(sprintf('unknown time zone %s', var_export($zoneId, true)));
        }

        return $zone->getOffset(new \DateTimeImmutable('1970-01-01T00:00:00', new \DateTimeZone('UTC')));
    }

    /**
     * @param array<int|string, string> $matches
     */
    private static function group(array $matches, int $index): ?string
    {
        return isset($matches[$index]) && $matches[$index] !== '' ? $matches[$index] : null;
    }
}
