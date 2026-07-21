<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Builtins;

use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ops;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelFunction;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;

/**
 * Argument narrowing for built-in functions, applying FEEL's implicit
 * conversions: a singleton list converts to its element where a scalar is
 * expected, and a scalar converts to a singleton list where a list is
 * expected.
 *
 * @internal
 */
final class Args
{
    private function __construct()
    {
    }

    public static function string(mixed $value, string $function, string $parameter): string
    {
        $value = self::unwrapSingleton($value);

        if (!\is_string($value)) {
            throw self::typeError($function, $parameter, 'a string', $value);
        }

        return $value;
    }

    public static function optionalString(mixed $value, string $function, string $parameter): ?string
    {
        return $value === null ? null : self::string($value, $function, $parameter);
    }

    public static function number(mixed $value, string $function, string $parameter): FeelNumber
    {
        $value = self::unwrapSingleton($value);

        if (!$value instanceof FeelNumber) {
            throw self::typeError($function, $parameter, 'a number', $value);
        }

        return $value;
    }

    public static function integer(mixed $value, string $function, string $parameter): int
    {
        $number = self::number($value, $function, $parameter);

        if (!$number->isInteger()) {
            throw self::typeError($function, $parameter, 'an integer', $value);
        }

        return $number->toInt();
    }

    /**
     * A number narrowed to int by truncating any fractional part toward
     * zero — the implicit conversion the DMN TCK expects of parameters such
     * as `decimal(scale)` and `substring(length)` (2.5 → 2, -2.5 → -2).
     */
    public static function truncatedInteger(mixed $value, string $function, string $parameter): int
    {
        $number = self::number($value, $function, $parameter);

        try {
            return $number->toBigDecimal()->toScale(0, RoundingMode::DOWN)->toInt();
        } catch (MathException) {
            throw self::typeError($function, $parameter, 'an integer', $value);
        }
    }

    public static function boolean(mixed $value, string $function, string $parameter): bool
    {
        $value = self::unwrapSingleton($value);

        if (!\is_bool($value)) {
            throw self::typeError($function, $parameter, 'a boolean', $value);
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    public static function listOf(mixed $value, string $function, string $parameter): array
    {
        if ($value === null) {
            throw self::typeError($function, $parameter, 'a list', $value);
        }

        if (Ops::isList($value)) {
            \assert(\is_array($value));

            return array_values($value);
        }

        return [$value];
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function context(mixed $value, string $function, string $parameter): array
    {
        if (!Ops::isContext($value)) {
            throw self::typeError($function, $parameter, 'a context', $value);
        }

        return Ops::contextToArray($value);
    }

    public static function function(mixed $value, string $function, string $parameter): FeelFunction
    {
        if (!$value instanceof FeelFunction) {
            throw self::typeError($function, $parameter, 'a function', $value);
        }

        return $value;
    }

    public static function unwrapSingleton(mixed $value): mixed
    {
        if (Ops::isList($value) && \is_array($value) && \count($value) === 1) {
            return $value[0];
        }

        return $value;
    }

    public static function typeError(string $function, string $parameter, string $expected, mixed $actual): FeelError
    {
        return new FeelError(sprintf(
            '%s(): %s must be %s, got %s',
            $function,
            $parameter,
            $expected,
            Ops::typeName($actual),
        ));
    }
}
