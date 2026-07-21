<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Value;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;

/**
 * The FEEL `number` type with IEEE 754-2008 decimal128 semantics:
 * 34 significant decimal digits, half-even rounding.
 *
 * Backed by brick/math BigDecimal; every arithmetic result is rounded back
 * to 34 significant digits with {@see RoundingMode::HALF_EVEN}.
 *
 * @property-read BigDecimal $decimal the decimal view, materialized lazily
 */
final class FeelNumber implements \Stringable
{
    public const int PRECISION = 34;

    /**
     * Safety bound for integer exponents; well beyond anything a decision
     * model legitimately computes, but keeps `2 ** 1e9` from locking a worker.
     */
    private const int MAX_INTEGER_EXPONENT = 10_000;

    /**
     * The integer fast rail: when the unscaled value fits a native int, the
     * number is exactly $unscaled * 10^-$scale, and comparison, addition,
     * subtraction and multiplication run as (overflow-guarded) native
     * integer arithmetic. Integer results are exact, so decimal128
     * semantics are untouched; anything that would overflow falls back to
     * BigDecimal.
     */
    private readonly ?int $unscaled;

    private readonly int $scale;

    /** Powers of ten for scale alignment on the fast rail. */
    private const array POW10 = [
        1,
        10,
        100,
        1_000,
        10_000,
        100_000,
        1_000_000,
        10_000_000,
        100_000_000,
        1_000_000_000,
        10_000_000_000,
        100_000_000_000,
        1_000_000_000_000,
        10_000_000_000_000,
        100_000_000_000_000,
        1_000_000_000_000_000,
        10_000_000_000_000_000,
        100_000_000_000_000_000,
    ];

    /** Backing storage for the lazily materialized decimal view. */
    private ?BigDecimal $materialized;

    /**
     * The decimal view. Fast-rail results (arithmetic chains) materialize
     * it only when something actually reads it; the rail itself is exact.
     */
    private function decimal(): BigDecimal
    {
        if ($this->materialized === null) {
            $unscaled = $this->unscaled;

            if ($unscaled === null) {
                throw new \LogicException('number carries neither a decimal nor a rail value');
            }

            $this->materialized = BigDecimal::ofUnscaledValue($unscaled, $this->scale);
        }

        return $this->materialized;
    }

    /**
     * Keeps the historical public `$decimal` property readable now that the
     * decimal materializes lazily behind it.
     */
    public function __get(string $name): BigDecimal
    {
        if ($name !== 'decimal') {
            throw new \LogicException(sprintf('undefined property %s', var_export($name, true)));
        }

        return $this->decimal();
    }

    public function __isset(string $name): bool
    {
        return $name === 'decimal';
    }

    private function __construct(?BigDecimal $materialized, ?int $unscaled, int $scale)
    {
        $this->materialized = $materialized;
        $this->unscaled = $unscaled;
        $this->scale = $scale;
    }

    private static function fromDecimal(BigDecimal $decimal): self
    {
        [$unscaled, $scale] = self::fastRail($decimal);

        return new self($decimal, $unscaled, $scale);
    }

    /**
     * A number whose rail representation is already known (fast-path
     * results): the decimal materializes on first read.
     */
    private static function fromRail(int $unscaled, int $scale): self
    {
        return new self(null, $unscaled, $scale);
    }

    /**
     * @return array{?int, int}
     */
    private static function fastRail(BigDecimal $decimal): array
    {
        $rendered = $decimal->__toString();

        // Up to 17 digits plus sign (and possibly a dot) always keeps the
        // unscaled value inside the int range.
        if (\strlen($rendered) > 18) {
            return [null, 0];
        }

        $scale = $decimal->getScale();

        if ($scale === 0) {
            return [(int) $rendered, 0];
        }

        return [(int) str_replace('.', '', $rendered), $scale];
    }

    /**
     * The fast rail is derived state: serialized models carry only the
     * decimal (this also keeps blobs written before the rail existed
     * restorable).
     *
     * @return array{decimal: BigDecimal}
     */
    public function __serialize(): array
    {
        return ['decimal' => $this->decimal()];
    }

    /**
     * @param array{decimal: BigDecimal} $data
     */
    public function __unserialize(array $data): void
    {
        $this->materialized = $data['decimal'];
        [$this->unscaled, $this->scale] = self::fastRail($data['decimal']);
    }

    /**
     * The unscaled value of $value aligned $by decimal places up, or null
     * when the shift leaves the int range.
     */
    private static function scaledUp(int $value, int $by): ?int
    {
        if ($by >= \count(self::POW10)) {
            return null;
        }

        $pow = self::POW10[$by];

        if ($value > \intdiv(\PHP_INT_MAX, $pow) || $value < \intdiv(\PHP_INT_MIN, $pow)) {
            return null;
        }

        return $value * $pow;
    }

    /**
     * Both operands' unscaled values aligned to the larger scale, or null
     * when either is off the rail or alignment overflows.
     *
     * @return array{int, int, int}|null [aligned left, aligned right, scale]
     */
    private function alignedWith(self $other): ?array
    {
        $x = $this->unscaled;
        $y = $other->unscaled;

        if ($x === null || $y === null) {
            return null;
        }

        $scale = max($this->scale, $other->scale);

        if ($this->scale !== $scale) {
            $x = self::scaledUp($x, $scale - $this->scale);
        }

        if ($other->scale !== $scale) {
            $y = self::scaledUp($y, $scale - $other->scale);
        }

        if ($x === null || $y === null) {
            return null;
        }

        return [$x, $y, $scale];
    }

    /**
     * Everyday integers (ages, counts, table constants) intern: they are
     * immutable, so one instance per value serves every use.
     */
    private const int SMALL_INT_LIMIT = 512;

    /** @var array<int, self> */
    private static array $smallInts = [];

    public static function of(BigNumber|int|float|string $value): self
    {
        if (\is_int($value)) {
            if ($value >= -self::SMALL_INT_LIMIT && $value <= self::SMALL_INT_LIMIT) {
                return self::$smallInts[$value] ??= self::fromRail($value, 0);
            }

            // The same bound the rail derivation applies: up to 18 rendered
            // characters including a sign.
            if ($value >= -99_999_999_999_999_999 && $value <= 999_999_999_999_999_999) {
                return self::fromRail($value, 0);
            }
        }

        if (\is_float($value)) {
            if (!\is_finite($value)) {
                throw new FeelError('cannot convert a non-finite float to a number');
            }

            $encoded = json_encode($value);
            \assert(\is_string($encoded));
            $value = $encoded;
        }

        try {
            $decimal = BigDecimal::of($value);
        } catch (MathException) {
            throw new FeelError(sprintf('invalid number %s', var_export((string) $value, true)));
        }

        return self::fromDecimal(self::round34($decimal));
    }

    public function plus(self $other): self
    {
        $aligned = $this->alignedWith($other);

        if ($aligned !== null) {
            [$x, $y, $scale] = $aligned;

            // Aligned values fit the int range; only the sum itself can
            // overflow. Integer sums are exact, so no rounding applies.
            if (!(($y > 0 && $x > \PHP_INT_MAX - $y) || ($y < 0 && $x < \PHP_INT_MIN - $y))) {
                return self::fromRail($x + $y, $scale);
            }
        }

        return self::fromDecimal(self::round34($this->decimal()->plus($other->decimal())));
    }

    public function minus(self $other): self
    {
        $aligned = $this->alignedWith($other);

        if ($aligned !== null) {
            [$x, $y, $scale] = $aligned;

            if (!(($y < 0 && $x > \PHP_INT_MAX + $y) || ($y > 0 && $x < \PHP_INT_MIN + $y))) {
                return self::fromRail($x - $y, $scale);
            }
        }

        return self::fromDecimal(self::round34($this->decimal()->minus($other->decimal())));
    }

    public function multipliedBy(self $other): self
    {
        $x = $this->unscaled;
        $y = $other->unscaled;

        // The unscaled product needs no alignment (scales add); it is exact
        // when it stays inside the int range, far below 34 digits.
        if ($x !== null && $y !== null && ($x === 0 || \intdiv(\PHP_INT_MAX, \abs($x)) >= \abs($y))) {
            return self::fromRail($x * $y, $this->scale + $other->scale);
        }

        return self::fromDecimal(self::round34($this->decimal()->multipliedBy($other->decimal())));
    }

    public function dividedBy(self $other): self
    {
        if ($other->decimal()->isZero()) {
            throw new FeelError('division by zero');
        }

        $scale = max(0, self::PRECISION - (self::exponent($this->decimal) - self::exponent($other->decimal())) + 2);

        try {
            $quotient = $this->decimal()->dividedBy($other->decimal(), $scale, RoundingMode::HALF_EVEN);
        } catch (MathException) {
            throw new FeelError('division failed');
        }

        return self::fromDecimal(self::round34($quotient));
    }

    public function power(self $exponent): self
    {
        $exp = $exponent->decimal;

        if (!$exp->hasNonZeroFractionalPart()) {
            try {
                $n = $exp->toScale(0)->toInt();
            } catch (MathException) {
                throw new FeelError('exponent out of range');
            }

            if (abs($n) > self::MAX_INTEGER_EXPONENT) {
                throw new FeelError('exponent out of range');
            }

            if ($n >= 0) {
                return self::fromDecimal(self::round34($this->decimal()->power($n)));
            }

            return self::of(1)->dividedBy(self::fromDecimal(self::round34($this->decimal()->power(-$n))));
        }

        // Non-integer exponents fall back to binary floating point; the DMN
        // specification leaves the precision of this case undefined.
        $result = ((float) (string) $this->decimal()) ** ((float) (string) $exp);

        if (!\is_finite($result)) {
            throw new FeelError('exponentiation did not yield a finite number');
        }

        return self::of($result);
    }

    public function negated(): self
    {
        if ($this->unscaled !== null) {
            return self::fromRail(-$this->unscaled, $this->scale);
        }

        return self::fromDecimal($this->decimal()->negated());
    }

    public function compareTo(self $other): int
    {
        $x = $this->unscaled;
        $y = $other->unscaled;

        if ($x !== null && $y !== null) {
            if ($this->scale === $other->scale) {
                return $x <=> $y;
            }

            if ($this->scale < $other->scale) {
                $x = self::scaledUp($x, $other->scale - $this->scale);

                if ($x !== null) {
                    return $x <=> $y;
                }
            } else {
                $y = self::scaledUp($y, $this->scale - $other->scale);

                if ($y !== null) {
                    return $x <=> $y;
                }
            }
        }

        return $this->decimal()->compareTo($other->decimal());
    }

    public function isEqualTo(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function isInteger(): bool
    {
        if ($this->unscaled !== null && $this->scale >= 0 && $this->scale < \count(self::POW10)) {
            return $this->scale === 0 || $this->unscaled % self::POW10[$this->scale] === 0;
        }

        return !$this->decimal()->hasNonZeroFractionalPart();
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->decimal();
    }

    /**
     * Rounds (half-even) to the nearest integer and returns it as int.
     */
    public function toInt(): int
    {
        try {
            return $this->decimal()->toScale(0, RoundingMode::HALF_EVEN)->toInt();
        } catch (MathException) {
            throw new FeelError('number out of integer range');
        }
    }

    public function __toString(): string
    {
        $literal = $this->decimal()->__toString();

        if ($this->decimal()->getScale() > 0) {
            $literal = rtrim(rtrim($literal, '0'), '.');
        }

        return $literal;
    }

    private static function round34(BigDecimal $decimal): BigDecimal
    {
        // The rendered length bounds the significant digits from above, so a
        // short literal (the common case) skips the exact digit count.
        if (\strlen($decimal->__toString()) <= self::PRECISION) {
            return $decimal;
        }

        $digits = self::significantDigits($decimal);

        if ($digits <= self::PRECISION) {
            return $decimal;
        }

        $newScale = $decimal->getScale() - ($digits - self::PRECISION);

        if ($newScale >= 0) {
            return $decimal->toScale($newScale, RoundingMode::HALF_EVEN);
        }

        // Rounding left of the decimal point: shift, round, shift back.
        $shift = -$newScale;

        return $decimal->withPointMovedLeft($shift)
            ->toScale(0, RoundingMode::HALF_EVEN)
            ->withPointMovedRight($shift);
    }

    private static function significantDigits(BigDecimal $decimal): int
    {
        if ($decimal->isZero()) {
            return 1;
        }

        return \strlen(ltrim($decimal->getUnscaledValue()->abs()->__toString(), '0'));
    }

    /**
     * Exponent of the most significant digit (0 for 1..9.99, 2 for 105, -1 for 0.5).
     */
    private static function exponent(BigDecimal $decimal): int
    {
        if ($decimal->isZero()) {
            return 0;
        }

        return self::significantDigits($decimal) - 1 - $decimal->getScale();
    }
}
