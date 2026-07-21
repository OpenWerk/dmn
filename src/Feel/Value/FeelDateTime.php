<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Value;

use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;

/**
 * The FEEL `date and time` type in its three flavors: local, with UTC offset,
 * or with an IANA zone id (`@"2027-01-01T00:00:00@Europe/Berlin"`).
 *
 * Local values are stored on a UTC-based DateTimeImmutable, so instant
 * comparisons between two local values degrade to the intended naive
 * field-wise comparison. Mixing local and zoned values in comparisons or
 * subtraction is an error (null per FEEL semantics).
 */
final class FeelDateTime implements \Stringable
{
    /**
     * @param int $nanoOfMicro the sub-microsecond digits (0–999) of a
     *                         nine-digit fraction — preserved for string
     *                         round-trips; arithmetic and comparisons stay
     *                         microsecond-based
     */
    public function __construct(
        public readonly \DateTimeImmutable $dateTime,
        public readonly ZoneKind $kind,
        public readonly int $nanoOfMicro = 0,
    ) {
    }

    public static function fromDateAndTime(FeelDate $date, FeelTime $time): self
    {
        if ($time->zoneId !== null) {
            $kind = ZoneKind::Zone;
            $timezone = self::timezone($time->zoneId);
        } elseif ($time->offsetSeconds !== null) {
            $kind = ZoneKind::Offset;
            $timezone = self::timezone(FeelTime::formatOffset($time->offsetSeconds));
        } else {
            $kind = ZoneKind::Local;
            $timezone = new \DateTimeZone('UTC');
        }

        $dateTime = (new \DateTimeImmutable('1970-01-01T00:00:00', $timezone))
            ->setDate($date->year, $date->month, $date->day)
            ->setTime($time->hour, $time->minute, $time->second, $time->microsecond);

        return new self($dateTime, $kind, $time->nanoOfMicro);
    }

    public function isZoned(): bool
    {
        return $this->kind !== ZoneKind::Local;
    }

    public function epochMicros(): int
    {
        return $this->dateTime->getTimestamp() * 1_000_000 + (int) $this->dateTime->format('u');
    }

    public function compareTo(self $other): int
    {
        if ($this->isZoned() !== $other->isZoned()) {
            throw new FeelError('cannot compare a zoned date and time with a local one');
        }

        return $this->epochMicros() <=> $other->epochMicros();
    }

    public function isEqualTo(self $other): bool
    {
        if ($this->isZoned() !== $other->isZoned()) {
            return false;
        }

        // Equality works at millisecond precision (the TCK's reference
        // granularity); ordering comparisons stay microsecond-exact.
        return intdiv($this->epochMicros(), 1_000) === intdiv($other->epochMicros(), 1_000);
    }

    public function plus(DaysTimeDuration $duration): self
    {
        return $this->withEpochMicros($this->epochMicros() + $duration->totalMicros());
    }

    public function plusMonths(int $months): self
    {
        [$year, $month, $day] = CalendarUtil::addMonthsClamped(
            (int) $this->dateTime->format('Y'),
            (int) $this->dateTime->format('n'),
            (int) $this->dateTime->format('j'),
            $months,
        );

        return new self($this->dateTime->setDate($year, $month, $day), $this->kind, $this->nanoOfMicro);
    }

    public function minus(self $other): DaysTimeDuration
    {
        if ($this->isZoned() !== $other->isZoned()) {
            throw new FeelError('cannot subtract between zoned and local date and time values');
        }

        return DaysTimeDuration::ofMicros($this->epochMicros() - $other->epochMicros());
    }

    public function datePart(): FeelDate
    {
        return new FeelDate(
            (int) $this->dateTime->format('Y'),
            (int) $this->dateTime->format('n'),
            (int) $this->dateTime->format('j'),
        );
    }

    public function timePart(): FeelTime
    {
        return new FeelTime(
            (int) $this->dateTime->format('G'),
            (int) $this->dateTime->format('i'),
            (int) $this->dateTime->format('s'),
            (int) $this->dateTime->format('u'),
            $this->kind === ZoneKind::Local ? null : $this->dateTime->getOffset(),
            $this->kind === ZoneKind::Zone ? $this->dateTime->getTimezone()->getName() : null,
            $this->nanoOfMicro,
        );
    }

    public function __toString(): string
    {
        $text = $this->dateTime->format('Y-m-d\TH:i:s');
        $nanos = (int) $this->dateTime->format('u') * 1_000 + $this->nanoOfMicro;

        if ($nanos > 0) {
            $text .= '.' . rtrim(sprintf('%09d', $nanos), '0');
        }

        return $text . match ($this->kind) {
            ZoneKind::Local => '',
            ZoneKind::Offset => $this->dateTime->getOffset() === 0 ? 'Z' : $this->dateTime->format('P'),
            ZoneKind::Zone => '@' . $this->dateTime->getTimezone()->getName(),
        };
    }

    private function withEpochMicros(int $micros): self
    {
        $seconds = intdiv($micros, 1_000_000);
        $micro = $micros % 1_000_000;

        if ($micro < 0) {
            $seconds--;
            $micro += 1_000_000;
        }

        $utc = new \DateTimeImmutable('@' . $seconds);
        $utc = $utc->setTime(
            (int) $utc->format('G'),
            (int) $utc->format('i'),
            (int) $utc->format('s'),
            $micro,
        );

        return new self($utc->setTimezone($this->dateTime->getTimezone()), $this->kind, $this->nanoOfMicro);
    }

    private static function timezone(string $identifier): \DateTimeZone
    {
        try {
            return new \DateTimeZone($identifier);
        } catch (\Exception) {
            throw new FeelError(sprintf('unknown time zone %s', var_export($identifier, true)));
        }
    }
}
