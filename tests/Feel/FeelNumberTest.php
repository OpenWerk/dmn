<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Feel;

use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeelNumberTest extends TestCase
{
    public function testDivisionRoundsTo34SignificantDigits(): void
    {
        $third = FeelNumber::of(1)->dividedBy(FeelNumber::of(3));

        self::assertSame('0.' . str_repeat('3', 34), (string) $third);
    }

    public function testMultiplicationRoundsHalfEven(): void
    {
        // 34 significant digits, the 35th decides: half-even rounding
        $a = FeelNumber::of('1.' . str_repeat('0', 32) . '5'); // 34 digits, exact
        $result = $a->multipliedBy(FeelNumber::of(10));

        self::assertSame('10.' . str_repeat('0', 31) . '5', (string) $result);
    }

    public function testHalfEvenRoundingOnConstruction(): void
    {
        // 35 digits: ...25 rounds to ...2 (even), ...35 rounds to ...4 (even)
        $down = FeelNumber::of('1.' . str_repeat('1', 32) . '25');
        $up = FeelNumber::of('1.' . str_repeat('1', 32) . '35');

        self::assertSame('1.' . str_repeat('1', 32) . '2', (string) $down);
        self::assertSame('1.' . str_repeat('1', 32) . '4', (string) $up);
    }

    public function testLargeIntegersRoundToPrecision(): void
    {
        $huge = FeelNumber::of(str_repeat('9', 35));

        // 35 nines round half-even at 34 digits -> 1 followed by 35 zeros
        self::assertSame('1' . str_repeat('0', 35), (string) $huge);
    }

    public function testDivisionByZeroIsAnError(): void
    {
        $this->expectException(FeelError::class);

        FeelNumber::of(1)->dividedBy(FeelNumber::of(0));
    }

    public function testIntegerPower(): void
    {
        self::assertSame('1024', (string) FeelNumber::of(2)->power(FeelNumber::of(10)));
        self::assertSame('0.25', (string) FeelNumber::of(2)->power(FeelNumber::of(-2)));
    }

    public function testFractionalPowerFallsBackToFloat(): void
    {
        $result = FeelNumber::of(4)->power(FeelNumber::of('0.5'));

        self::assertSame('2', (string) $result);
    }

    public function testEqualityIgnoresScale(): void
    {
        self::assertTrue(FeelNumber::of('1.0')->isEqualTo(FeelNumber::of(1)));
        self::assertSame(0, FeelNumber::of('1.10')->compareTo(FeelNumber::of('1.1')));
    }

    public function testFloatConversionUsesShortestRoundTrip(): void
    {
        self::assertSame('0.1', (string) FeelNumber::of(0.1));
        self::assertSame('12.34', (string) FeelNumber::of(12.34));
    }

    #[DataProvider('canonicalStrings')]
    public function testCanonicalStringForm(string $input, string $expected): void
    {
        self::assertSame($expected, (string) FeelNumber::of($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function canonicalStrings(): iterable
    {
        yield 'trailing zeros stripped' => ['1.500', '1.5'];
        yield 'integer scale stripped' => ['100.00', '100'];
        yield 'zero' => ['0.000', '0'];
        yield 'negative' => ['-2.50', '-2.5'];
    }
}
