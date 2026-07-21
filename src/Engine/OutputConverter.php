<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Engine;

use OpenWerk\DecisionModelAndNotation\Feel\Value\EmptyContext;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;

/**
 * Converts FEEL runtime values into the shapes the public API promises:
 * numbers as brick/math BigDecimal, temporal values as their wrapper
 * objects, lists and contexts as arrays.
 *
 * @internal
 */
final class OutputConverter
{
    private function __construct()
    {
    }

    public static function toPhp(mixed $value): mixed
    {
        if ($value instanceof FeelNumber) {
            return $value->toBigDecimal();
        }

        if ($value instanceof EmptyContext) {
            return [];
        }

        if (\is_array($value)) {
            $converted = [];

            foreach ($value as $key => $element) {
                $converted[$key] = self::toPhp($element);
            }

            return $converted;
        }

        return $value;
    }
}
