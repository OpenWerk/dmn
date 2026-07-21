<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Tck;

use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;
use OpenWerk\DecisionModelAndNotation\Tck\TckComparator;
use PHPUnit\Framework\TestCase;

final class TckComparatorTest extends TestCase
{
    public function testNumbersCompareWithinTolerance(): void
    {
        // The TCK reference runners use an absolute tolerance of 1e-8.
        self::assertTrue(TckComparator::equals(
            FeelNumber::of('2778.69354943277'),
            FeelNumber::of('2778.693549432766768088520383236299'),
        ));

        self::assertFalse(TckComparator::equals(
            FeelNumber::of('2778.6935'),
            FeelNumber::of('2778.6936'),
        ));
    }

    public function testToleranceAppliesInsideStructures(): void
    {
        $expected = ['payment' => FeelNumber::of('1.00000000'), 'grade' => 'ok'];
        $actual = ['payment' => FeelNumber::of('1.000000001'), 'grade' => 'ok'];

        self::assertTrue(TckComparator::equals($expected, $actual));

        self::assertTrue(TckComparator::equals(
            [FeelNumber::of(1), FeelNumber::of(2)],
            [FeelNumber::of('1.000000001'), FeelNumber::of(2)],
        ));
    }

    public function testStructuralMismatchesFail(): void
    {
        self::assertFalse(TckComparator::equals(['a' => 1], ['b' => 1]));
        self::assertFalse(TckComparator::equals([1, 2], [1]));
        self::assertFalse(TckComparator::equals('x', null));
        self::assertFalse(TckComparator::equals('x', FeelNumber::of(1)));
        self::assertTrue(TckComparator::equals(null, null));
        self::assertTrue(TckComparator::equals('x', 'x'));
    }
}
