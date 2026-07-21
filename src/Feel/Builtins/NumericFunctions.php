<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Builtins;

use Brick\Math\RoundingMode;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;
use OpenWerk\DecisionModelAndNotation\Feel\Value\DaysTimeDuration;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;
use OpenWerk\DecisionModelAndNotation\Feel\Value\YearsMonthsDuration;

/**
 * Numeric functions (DMN 1.5, 10.3.4.2). The transcendental functions
 * (sqrt, log, exp, stddev) go through binary floats — a documented
 * precision deviation.
 *
 * @internal
 */
final class NumericFunctions
{
    /** FEEL's scale bounds for the rounding functions (DMN 1.5). */
    private const int MIN_SCALE = -6_111;
    private const int MAX_SCALE = 6_176;

    private function __construct()
    {
    }

    public static function register(BuiltinCatalog $catalog): void
    {
        // decimal() truncates a fractional scale (2.5 → 2) per the TCK's
        // implicit-conversion expectations; the other rounders stay strict.
        $catalog->register(
            'decimal',
            [[['n', 'scale'], 2]],
            self::rounder(RoundingMode::HALF_EVEN, 'decimal', truncatedScale: true),
        );
        $catalog->register('floor', [[['n', 'scale'], 1]], self::rounder(RoundingMode::FLOOR, 'floor'));
        $catalog->register('ceiling', [[['n', 'scale'], 1]], self::rounder(RoundingMode::CEILING, 'ceiling'));
        $catalog->register('round up', [[['n', 'scale'], 1]], self::rounder(RoundingMode::UP, 'round up'));
        $catalog->register('round down', [[['n', 'scale'], 1]], self::rounder(RoundingMode::DOWN, 'round down'));
        $catalog->register(
            'round half up',
            [[['n', 'scale'], 1]],
            self::rounder(RoundingMode::HALF_UP, 'round half up'),
        );
        $catalog->register(
            'round half down',
            [[['n', 'scale'], 1]],
            self::rounder(RoundingMode::HALF_DOWN, 'round half down'),
        );
        $catalog->register(
            'round half even',
            [[['n', 'scale'], 1]],
            self::rounder(RoundingMode::HALF_EVEN, 'round half even'),
        );

        $catalog->register(
            'abs',
            [[['n'], 1]],
            static function (array $args): mixed {
                $value = Args::unwrapSingleton($args[0]);

                if ($value instanceof FeelNumber) {
                    return $value->compareTo(FeelNumber::of(0)) < 0 ? $value->negated() : $value;
                }

                if ($value instanceof YearsMonthsDuration) {
                    return $value->months < 0 ? $value->negated() : $value;
                }

                if ($value instanceof DaysTimeDuration) {
                    return $value->totalMicros() < 0 ? $value->negated() : $value;
                }

                throw Args::typeError('abs', 'n', 'a number or duration', $value);
            },
        );

        $catalog->register(
            'modulo',
            [[['dividend', 'divisor'], 2]],
            static function (array $args): mixed {
                $dividend = Args::number($args[0], 'modulo', 'dividend');
                $divisor = Args::number($args[1], 'modulo', 'divisor');

                if ($divisor->toBigDecimal()->isZero()) {
                    throw new FeelError('modulo(): division by zero');
                }

                // FEEL: modulo(dividend, divisor) = dividend - divisor*floor(dividend/divisor)
                $quotient = $dividend->dividedBy($divisor)->toBigDecimal()->toScale(0, RoundingMode::FLOOR);

                return $dividend->minus($divisor->multipliedBy(FeelNumber::of($quotient)));
            },
        );

        $catalog->register('sqrt', [[['number'], 1]], static function (array $args): mixed {
            $number = Args::number($args[0], 'sqrt', 'number');

            if ($number->compareTo(FeelNumber::of(0)) < 0) {
                throw new FeelError('sqrt(): number must not be negative');
            }

            return self::viaFloat(sqrt((float) (string) $number->toBigDecimal()), 'sqrt');
        });

        $catalog->register('log', [[['number'], 1]], static function (array $args): mixed {
            $number = Args::number($args[0], 'log', 'number');

            if ($number->compareTo(FeelNumber::of(0)) <= 0) {
                throw new FeelError('log(): number must be positive');
            }

            return self::viaFloat(log((float) (string) $number->toBigDecimal()), 'log');
        });

        $catalog->register('exp', [[['number'], 1]], static function (array $args): mixed {
            $number = Args::number($args[0], 'exp', 'number');

            return self::viaFloat(exp((float) (string) $number->toBigDecimal()), 'exp');
        });

        $catalog->register('odd', [[['number'], 1]], static function (array $args): mixed {
            $number = Args::number($args[0], 'odd', 'number');

            return $number->isInteger() && abs($number->toInt()) % 2 === 1;
        });

        $catalog->register('even', [[['number'], 1]], static function (array $args): mixed {
            $number = Args::number($args[0], 'even', 'number');

            return $number->isInteger() && $number->toInt() % 2 === 0;
        });
    }

    /**
     * @return \Closure
     */
    private static function rounder(RoundingMode $mode, string $function, bool $truncatedScale = false): \Closure
    {
        return static function (array $args, array $provided) use ($mode, $function, $truncatedScale): mixed {
            $number = Args::number($args[0], $function, 'n');

            // An omitted scale defaults to 0; an explicit null is an error.
            $scale = match (true) {
                !($provided[1] ?? false) => 0,
                $truncatedScale => Args::truncatedInteger($args[1], $function, 'scale'),
                default => Args::integer($args[1], $function, 'scale'),
            };

            if ($scale < self::MIN_SCALE || $scale > self::MAX_SCALE) {
                throw new FeelError(sprintf('%s(): scale is out of range', $function));
            }

            $decimal = $number->toBigDecimal();

            if ($scale >= 0) {
                return FeelNumber::of($decimal->toScale($scale, $mode));
            }

            $shift = -$scale;

            return FeelNumber::of(
                $decimal->withPointMovedLeft($shift)->toScale(0, $mode)->withPointMovedRight($shift),
            );
        };
    }

    private static function viaFloat(float $result, string $function): FeelNumber
    {
        if (!is_finite($result)) {
            throw new FeelError(sprintf('%s(): result is not a finite number', $function));
        }

        return FeelNumber::of($result);
    }
}
