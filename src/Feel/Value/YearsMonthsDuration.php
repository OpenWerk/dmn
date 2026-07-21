<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Value;

use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;

/**
 * The FEEL `years and months duration` type, normalized to a total number of
 * months (possibly negative).
 */
final class YearsMonthsDuration implements \Stringable
{
    public function __construct(public readonly int $months)
    {
    }

    public function plus(self $other): self
    {
        return new self($this->months + $other->months);
    }

    public function minus(self $other): self
    {
        return new self($this->months - $other->months);
    }

    public function negated(): self
    {
        return new self(-$this->months);
    }

    /**
     * Multiplication by a number truncates the resulting month count toward
     * zero (a years-and-months duration is integral by definition; the TCK
     * pins truncation).
     */
    public function multipliedBy(FeelNumber $factor): self
    {
        return new self(self::truncate(FeelNumber::of($this->months)->multipliedBy($factor)));
    }

    public function dividedBy(FeelNumber $divisor): self
    {
        return new self(self::truncate(FeelNumber::of($this->months)->dividedBy($divisor)));
    }

    private static function truncate(FeelNumber $months): int
    {
        try {
            return $months->toBigDecimal()->toScale(0, \Brick\Math\RoundingMode::DOWN)->toInt();
        } catch (\Brick\Math\Exception\MathException) {
            throw new FeelError('duration out of integer range');
        }
    }

    /**
     * duration / duration yields a plain number.
     */
    public function ratio(self $other): FeelNumber
    {
        if ($other->months === 0) {
            throw new FeelError('division by zero duration');
        }

        return FeelNumber::of($this->months)->dividedBy(FeelNumber::of($other->months));
    }

    public function compareTo(self $other): int
    {
        return $this->months <=> $other->months;
    }

    public function isEqualTo(self $other): bool
    {
        return $this->months === $other->months;
    }

    public function __toString(): string
    {
        $abs = abs($this->months);
        $years = intdiv($abs, 12);
        $months = $abs % 12;
        $text = $this->months < 0 ? '-P' : 'P';

        if ($years > 0) {
            $text .= $years . 'Y';
        }

        if ($months > 0 || $years === 0) {
            $text .= $months . 'M';
        }

        return $text;
    }
}
