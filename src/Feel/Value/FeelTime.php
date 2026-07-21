<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Value;

use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;

/**
 * The FEEL `time` type: a wall-clock time, optionally with a UTC offset or an
 * IANA zone id.
 *
 * Deviation (documented): for zone-id times the offset is resolved against
 * the epoch date 1970-01-01, because a time carries no date to resolve
 * daylight-saving transitions with.
 */
final class FeelTime implements \Stringable
{
    private const int MICROS_PER_DAY = 86_400_000_000;

    /**
     * @param int $nanoOfMicro the sub-microsecond digits (0–999) of a
     *                         nine-digit fraction — preserved for string
     *                         round-trips; arithmetic and comparisons stay
     *                         microsecond-based
     */
    public function __construct(
        public readonly int $hour,
        public readonly int $minute,
        public readonly int $second,
        public readonly int $microsecond = 0,
        public readonly ?int $offsetSeconds = null,
        public readonly ?string $zoneId = null,
        public readonly int $nanoOfMicro = 0,
    ) {
        if (
            $hour < 0 || $hour > 23
            || $minute < 0 || $minute > 59
            || $second < 0 || $second > 59
            || $microsecond < 0 || $microsecond > 999_999
            || $nanoOfMicro < 0 || $nanoOfMicro > 999
        ) {
            throw new FeelError(sprintf('invalid time %02d:%02d:%02d', $hour, $minute, $second));
        }

        if ($offsetSeconds !== null && abs($offsetSeconds) > 18 * 3_600) {
            throw new FeelError('invalid time zone offset');
        }

        if ($zoneId !== null && $offsetSeconds === null) {
            throw new FeelError('a zoned time requires a resolved offset');
        }
    }

    public function isZoned(): bool
    {
        return $this->offsetSeconds !== null;
    }

    public function microsOfDay(): int
    {
        return ($this->hour * 3_600 + $this->minute * 60 + $this->second) * 1_000_000 + $this->microsecond;
    }

    public function compareTo(self $other): int
    {
        if ($this->isZoned() !== $other->isZoned()) {
            throw new FeelError('cannot compare a zoned time with a local time');
        }

        return $this->utcMicros() <=> $other->utcMicros();
    }

    public function isEqualTo(self $other): bool
    {
        if ($this->isZoned() !== $other->isZoned()) {
            return false;
        }

        // Equality works at millisecond precision (the TCK's reference
        // granularity); ordering comparisons stay microsecond-exact.
        return intdiv($this->utcMicros(), 1_000) === intdiv($other->utcMicros(), 1_000);
    }

    public function plus(DaysTimeDuration $duration): self
    {
        $micros = $this->microsOfDay() + $duration->totalMicros();
        $wrapped = (($micros % self::MICROS_PER_DAY) + self::MICROS_PER_DAY) % self::MICROS_PER_DAY;

        return self::fromMicrosOfDay($wrapped, $this->offsetSeconds, $this->zoneId);
    }

    public function minus(self $other): DaysTimeDuration
    {
        if ($this->isZoned() !== $other->isZoned()) {
            throw new FeelError('cannot subtract a local time from a zoned time');
        }

        return DaysTimeDuration::ofMicros($this->utcMicros() - $other->utcMicros());
    }

    public static function fromMicrosOfDay(int $micros, ?int $offsetSeconds = null, ?string $zoneId = null): self
    {
        $second = intdiv($micros, 1_000_000);
        $microsecond = $micros % 1_000_000;

        return new self(
            intdiv($second, 3_600),
            intdiv($second % 3_600, 60),
            $second % 60,
            $microsecond,
            $offsetSeconds,
            $zoneId,
        );
    }

    public function __toString(): string
    {
        $text = sprintf('%02d:%02d:%02d', $this->hour, $this->minute, $this->second);
        $nanos = $this->microsecond * 1_000 + $this->nanoOfMicro;

        if ($nanos > 0) {
            $text .= '.' . rtrim(sprintf('%09d', $nanos), '0');
        }

        if ($this->zoneId !== null) {
            return $text . '@' . $this->zoneId;
        }

        if ($this->offsetSeconds !== null) {
            $text .= self::formatOffset($this->offsetSeconds);
        }

        return $text;
    }

    public static function formatOffset(int $offsetSeconds): string
    {
        if ($offsetSeconds === 0) {
            return 'Z';
        }

        $sign = $offsetSeconds < 0 ? '-' : '+';
        $abs = abs($offsetSeconds);
        $text = sprintf('%s%02d:%02d', $sign, intdiv($abs, 3_600), intdiv($abs % 3_600, 60));

        // Duration-built offsets may carry seconds ("+02:45:55").
        if ($abs % 60 !== 0) {
            $text .= sprintf(':%02d', $abs % 60);
        }

        return $text;
    }

    private function utcMicros(): int
    {
        return $this->microsOfDay() - ($this->offsetSeconds ?? 0) * 1_000_000;
    }
}
