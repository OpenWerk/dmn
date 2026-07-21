<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tck;

use Brick\Math\BigDecimal;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ops;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;

/**
 * TCK result comparison. Follows the reference runners: numbers compare
 * equal within an absolute tolerance of 1e-8 (several TCK expected values
 * carry only double precision); everything else uses FEEL equality.
 *
 * @internal
 */
final class TckComparator
{
    private const string NUMBER_TOLERANCE = '0.00000001';

    private function __construct()
    {
    }

    public static function equals(mixed $expected, mixed $actual): bool
    {
        if ($expected instanceof FeelNumber && $actual instanceof FeelNumber) {
            return $expected->toBigDecimal()
                ->minus($actual->toBigDecimal())
                ->abs()
                ->isLessThan(BigDecimal::of(self::NUMBER_TOLERANCE));
        }

        if (\is_array($expected) && \is_array($actual)) {
            return self::arrayEquals($expected, $actual);
        }

        return Ops::equals($expected, $actual) === true;
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     */
    private static function arrayEquals(array $expected, array $actual): bool
    {
        if (array_is_list($expected) !== array_is_list($actual) || \count($expected) !== \count($actual)) {
            return false;
        }

        if (!array_is_list($expected)) {
            $expectedKeys = array_map(strval(...), array_keys($expected));
            $actualKeys = array_map(strval(...), array_keys($actual));
            sort($expectedKeys);
            sort($actualKeys);

            if ($expectedKeys !== $actualKeys) {
                return false;
            }
        }

        foreach ($expected as $key => $value) {
            if (!\array_key_exists($key, $actual) || !self::equals($value, $actual[$key])) {
                return false;
            }
        }

        return true;
    }
}
