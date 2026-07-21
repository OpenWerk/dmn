<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Engine;

use Brick\Math\BigDecimal;
use OpenWerk\DecisionModelAndNotation\Engine\InputConverter;
use OpenWerk\DecisionModelAndNotation\Engine\OutputConverter;
use OpenWerk\DecisionModelAndNotation\Exception\InvalidInputException;
use OpenWerk\DecisionModelAndNotation\Feel\Value\DaysTimeDuration;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDateTime;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;
use OpenWerk\DecisionModelAndNotation\Feel\Value\YearsMonthsDuration;
use OpenWerk\DecisionModelAndNotation\Feel\Value\ZoneKind;
use OpenWerk\DecisionModelAndNotation\Tests\Support\ValueAssertions;
use PHPUnit\Framework\TestCase;

final class InputConverterTest extends TestCase
{
    use ValueAssertions;

    public function testScalars(): void
    {
        self::assertNull(InputConverter::toFeel(null));
        self::assertTrue(InputConverter::toFeel(true));
        self::assertSame('x', InputConverter::toFeel('x'));

        $int = InputConverter::toFeel(42);
        self::assertInstanceOf(FeelNumber::class, $int);
        self::assertSame('42', (string) $int);

        $float = InputConverter::toFeel(0.1);
        self::assertInstanceOf(FeelNumber::class, $float);
        self::assertSame('0.1', (string) $float);
    }

    public function testBigDecimalPassesThroughAsNumber(): void
    {
        $value = InputConverter::toFeel(BigDecimal::of('1.50'));

        self::assertInstanceOf(FeelNumber::class, $value);
        self::assertSame('1.5', (string) $value);
    }

    public function testNestedArraysBecomeListsAndContexts(): void
    {
        $value = self::arr(InputConverter::toFeel(['a' => [1, 2], 'b' => ['c' => true]]));

        $list = self::arr($value['a']);
        self::assertInstanceOf(FeelNumber::class, $list[0]);

        $context = self::arr($value['b']);
        self::assertTrue($context['c']);
    }

    public function testDateTimeInterfaceBecomesFeelDateTime(): void
    {
        $berlin = new \DateTimeZone('Europe/Berlin');
        $zoned = InputConverter::toFeel(new \DateTimeImmutable('2027-01-01T10:00:00', $berlin));

        self::assertInstanceOf(FeelDateTime::class, $zoned);
        self::assertSame(ZoneKind::Zone, $zoned->kind);

        $offset = InputConverter::toFeel(new \DateTimeImmutable('2027-01-01T10:00:00+02:00'));

        self::assertInstanceOf(FeelDateTime::class, $offset);
        self::assertSame(ZoneKind::Offset, $offset->kind);
    }

    public function testDateIntervalBecomesDuration(): void
    {
        $months = InputConverter::toFeel(new \DateInterval('P1Y2M'));
        self::assertInstanceOf(YearsMonthsDuration::class, $months);
        self::assertSame('P1Y2M', (string) $months);

        $seconds = InputConverter::toFeel(new \DateInterval('PT90M'));
        self::assertInstanceOf(DaysTimeDuration::class, $seconds);
        self::assertSame('PT1H30M', (string) $seconds);
    }

    public function testNonFiniteFloatIsRejected(): void
    {
        $this->expectException(InvalidInputException::class);

        InputConverter::toFeel(NAN);
    }

    public function testUnsupportedObjectIsRejected(): void
    {
        $this->expectException(InvalidInputException::class);

        InputConverter::toFeel(new \stdClass());
    }

    public function testOutputConverterUnwrapsNumbersRecursively(): void
    {
        $php = self::arr(OutputConverter::toPhp([
            'n' => FeelNumber::of('1.5'),
            'list' => [FeelNumber::of(1), 'x'],
        ]));

        self::assertInstanceOf(BigDecimal::class, $php['n']);

        $list = self::arr($php['list']);
        self::assertInstanceOf(BigDecimal::class, $list[0]);
        self::assertSame('x', $list[1]);
    }
}
