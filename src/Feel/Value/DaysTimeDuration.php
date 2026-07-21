<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Value;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;

/**
 * The FEEL `days and time duration` type, normalized to seconds with
 * microsecond precision.
 */
final class DaysTimeDuration implements \Stringable
{
    private const int MICROS_PER_SECOND = 1_000_000;

    /** Roughly ±31,000 years — keeps totalMicros() safely inside the int range. */
    private const int MAX_SECONDS = 1_000_000_000_000;

    /**
     * @param int $seconds whole seconds, sign carries the duration's sign
     * @param int $micros  microsecond part, same sign as $seconds, |micros| < 1e6
     */
    private function __construct(
        public readonly int $seconds,
        public readonly int $micros,
    ) {
        if (abs($seconds) > self::MAX_SECONDS) {
            throw new FeelError('duration out of supported range');
        }
    }

    public static function ofSeconds(int $seconds): self
    {
        return new self($seconds, 0);
    }

    public static function ofMicros(int $micros): self
    {
        return new self(intdiv($micros, self::MICROS_PER_SECOND), $micros % self::MICROS_PER_SECOND);
    }

    public static function ofDecimalSeconds(BigDecimal $seconds): self
    {
        try {
            $micros = $seconds->multipliedBy(self::MICROS_PER_SECOND)
                ->toScale(0, RoundingMode::HALF_EVEN)
                ->toInt();
        } catch (MathException) {
            throw new FeelError('duration out of supported range');
        }

        return self::ofMicros($micros);
    }

    public function totalMicros(): int
    {
        return $this->seconds * self::MICROS_PER_SECOND + $this->micros;
    }

    public function toDecimalSeconds(): BigDecimal
    {
        return BigDecimal::of($this->seconds)
            ->plus(BigDecimal::of($this->micros)->exactlyDividedBy(self::MICROS_PER_SECOND));
    }

    public function plus(self $other): self
    {
        return self::ofMicros($this->totalMicros() + $other->totalMicros());
    }

    public function minus(self $other): self
    {
        return self::ofMicros($this->totalMicros() - $other->totalMicros());
    }

    public function negated(): self
    {
        return self::ofMicros(-$this->totalMicros());
    }

    public function multipliedBy(FeelNumber $factor): self
    {
        return self::ofDecimalSeconds(
            FeelNumber::of($this->toDecimalSeconds())->multipliedBy($factor)->toBigDecimal()
        );
    }

    public function dividedBy(FeelNumber $divisor): self
    {
        return self::ofDecimalSeconds(
            FeelNumber::of($this->toDecimalSeconds())->dividedBy($divisor)->toBigDecimal()
        );
    }

    /**
     * duration / duration yields a plain number.
     */
    public function ratio(self $other): FeelNumber
    {
        if ($other->totalMicros() === 0) {
            throw new FeelError('division by zero duration');
        }

        return FeelNumber::of($this->toDecimalSeconds())
            ->dividedBy(FeelNumber::of($other->toDecimalSeconds()));
    }

    public function compareTo(self $other): int
    {
        return $this->totalMicros() <=> $other->totalMicros();
    }

    public function isEqualTo(self $other): bool
    {
        return $this->totalMicros() === $other->totalMicros();
    }

    public function __toString(): string
    {
        $total = abs($this->totalMicros());
        $micro = $total % self::MICROS_PER_SECOND;
        $secondsTotal = intdiv($total, self::MICROS_PER_SECOND);

        $days = intdiv($secondsTotal, 86_400);
        $hours = intdiv($secondsTotal % 86_400, 3_600);
        $minutes = intdiv($secondsTotal % 3_600, 60);
        $seconds = $secondsTotal % 60;

        $text = $this->totalMicros() < 0 ? '-P' : 'P';

        if ($days > 0) {
            $text .= $days . 'D';
        }

        $timeText = '';

        if ($hours > 0) {
            $timeText .= $hours . 'H';
        }

        if ($minutes > 0) {
            $timeText .= $minutes . 'M';
        }

        if ($seconds > 0 || $micro > 0) {
            $timeText .= $seconds;

            if ($micro > 0) {
                $timeText .= '.' . rtrim(sprintf('%06d', $micro), '0');
            }

            $timeText .= 'S';
        }

        if ($timeText !== '') {
            return $text . 'T' . $timeText;
        }

        if ($days > 0) {
            return $text;
        }

        return $text . 'T0S';
    }
}
