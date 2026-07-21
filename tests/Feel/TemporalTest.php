<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Feel;

use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ops;
use OpenWerk\DecisionModelAndNotation\Feel\Value\DaysTimeDuration;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDate;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDateTime;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelTime;
use OpenWerk\DecisionModelAndNotation\Feel\Value\TemporalParser;
use OpenWerk\DecisionModelAndNotation\Feel\Value\YearsMonthsDuration;
use OpenWerk\DecisionModelAndNotation\Tests\Support\ValueAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemporalTest extends TestCase
{
    use ValueAssertions;

    public function testParsesDate(): void
    {
        $date = TemporalParser::date('2027-02-28');

        self::assertSame(2027, $date->year);
        self::assertSame(2, $date->month);
        self::assertSame(28, $date->day);
        self::assertSame('2027-02-28', (string) $date);
    }

    public function testRejectsInvalidDates(): void
    {
        $this->expectException(FeelError::class);

        TemporalParser::date('2027-02-29'); // 2027 is not a leap year
    }

    public function testNineFractionalDigitsSurviveTheRoundTrip(): void
    {
        $time = TemporalParser::time('10:15:30.123456789');

        self::assertSame(123_456, $time->microsecond);
        self::assertSame(789, $time->nanoOfMicro);
        self::assertSame('10:15:30.123456789', (string) $time);

        $dateTime = TemporalParser::dateAndTime('2011-12-31T10:15:30.123456789@Europe/Paris');

        self::assertSame('2011-12-31T10:15:30.123456789@Europe/Paris', (string) $dateTime);
        self::assertSame('10:15:30.123456789@Europe/Paris', (string) $dateTime->timePart());

        // Shorter fractions keep their microsecond behavior.
        self::assertSame('10:15:30.5', (string) TemporalParser::time('10:15:30.5'));
        self::assertSame('10:15:30', (string) TemporalParser::time('10:15:30.000000000'));
    }

    public function testNanosecondsComposeThroughDateAndTime(): void
    {
        $composed = FeelDateTime::fromDateAndTime(
            new FeelDate(2017, 1, 1),
            TemporalParser::time('23:59:01.123456789@Europe/Paris'),
        );

        self::assertSame('2017-01-01T23:59:01.123456789@Europe/Paris', (string) $composed);
    }

    public function testNanosecondsDoNotDisturbEqualityGranularity(): void
    {
        // Equality stays at millisecond precision; the extra digits only
        // matter for lexical round-trips.
        self::assertTrue(
            TemporalParser::time('10:15:30.123456789')->isEqualTo(TemporalParser::time('10:15:30.123999999')),
        );
        self::assertFalse(
            TemporalParser::time('10:15:30.123456789')->isEqualTo(TemporalParser::time('10:15:30.124999999')),
        );
    }

    public function testHugeYearsRoundTripOnDateAndTime(): void
    {
        self::assertSame(
            '999999999-12-31T23:59:59.999999999@Europe/Paris',
            (string) TemporalParser::dateAndTime('999999999-12-31T23:59:59.999999999@Europe/Paris'),
        );
        self::assertSame(
            '-999999999-12-31T23:59:59.999999999+02:00',
            (string) TemporalParser::dateAndTime('-999999999-12-31T23:59:59.999999999+02:00'),
        );
    }

    public function testLeapYearIsAccepted(): void
    {
        self::assertSame('2028-02-29', (string) TemporalParser::date('2028-02-29'));
    }

    public function testParsesTimeFlavors(): void
    {
        self::assertSame('11:00:00', (string) TemporalParser::time('11:00:00'));
        self::assertSame('11:00:00Z', (string) TemporalParser::time('11:00:00Z'));
        self::assertSame('11:00:00+05:30', (string) TemporalParser::time('11:00:00+05:30'));
        self::assertSame('11:00:00.5', (string) TemporalParser::time('11:00:00.500'));
        self::assertSame('11:00:00@Europe/Paris', (string) TemporalParser::time('11:00:00@Europe/Paris'));
    }

    public function testParsesDateTimeFlavors(): void
    {
        self::assertSame(
            '2027-01-01T10:30:00',
            (string) TemporalParser::dateAndTime('2027-01-01T10:30:00'),
        );
        self::assertSame(
            '2027-01-01T10:30:00Z',
            (string) TemporalParser::dateAndTime('2027-01-01T10:30:00Z'),
        );
        self::assertSame(
            '2027-01-01T10:30:00+02:00',
            (string) TemporalParser::dateAndTime('2027-01-01T10:30:00+02:00'),
        );
        self::assertSame(
            '2027-01-01T00:00:00@Europe/Berlin',
            (string) TemporalParser::dateAndTime('2027-01-01T00:00:00@Europe/Berlin'),
        );
        self::assertSame(
            '2027-01-01T00:00:00',
            (string) TemporalParser::dateAndTime('2027-01-01'),
        );
    }

    #[DataProvider('durations')]
    public function testParsesAndNormalizesDurations(string $input, string $canonical): void
    {
        self::assertSame($canonical, (string) TemporalParser::duration($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function durations(): iterable
    {
        yield 'years and months' => ['P1Y2M', 'P1Y2M'];
        yield 'months overflow' => ['P26M', 'P2Y2M'];
        yield 'negative months' => ['-P3M', '-P3M'];
        yield 'days and time' => ['P1DT2H3M4S', 'P1DT2H3M4S'];
        yield 'hours overflow' => ['PT26H', 'P1DT2H'];
        yield 'fractional seconds' => ['PT0.5S', 'PT0.5S'];
        yield 'zero seconds' => ['PT0S', 'PT0S'];
        yield 'zero months' => ['P0M', 'P0M'];
        yield 'negative time' => ['-PT30M', '-PT30M'];
    }

    public function testRejectsMixedDuration(): void
    {
        $this->expectException(FeelError::class);

        TemporalParser::duration('P1Y1D');
    }

    public function testAtLiteralDispatch(): void
    {
        self::assertInstanceOf(FeelDate::class, TemporalParser::atLiteral('2027-01-01'));
        self::assertInstanceOf(FeelTime::class, TemporalParser::atLiteral('11:00:00'));
        self::assertInstanceOf(FeelDateTime::class, TemporalParser::atLiteral('2027-01-01T11:00:00@Europe/Berlin'));
        self::assertInstanceOf(YearsMonthsDuration::class, TemporalParser::atLiteral('P1Y'));
        self::assertInstanceOf(DaysTimeDuration::class, TemporalParser::atLiteral('-PT2H'));
    }

    public function testDateComparisonAndSubtraction(): void
    {
        $a = TemporalParser::date('2027-01-01');
        $b = TemporalParser::date('2027-03-01');

        self::assertSame(-1, $a->compareTo($b));
        self::assertSame('P59D', (string) $b->minus($a));
    }

    public function testMonthAdditionClampsDayOfMonth(): void
    {
        $date = TemporalParser::date('2027-01-31');

        self::assertSame('2027-02-28', (string) $date->addMonths(1));
        self::assertSame('2028-02-29', (string) $date->addMonths(13));
        self::assertSame('2026-12-31', (string) $date->addMonths(-1));
    }

    public function testDatePlusDurationViaOps(): void
    {
        $date = TemporalParser::date('2027-01-01');

        $plusMonths = Ops::add($date, TemporalParser::duration('P1Y2M'));
        self::assertSame('2028-03-01', self::str($plusMonths));

        $plusDays = Ops::add($date, TemporalParser::duration('P2DT26H'));
        self::assertSame('2027-01-04', self::str($plusDays));

        $minusHours = Ops::subtract($date, TemporalParser::duration('PT2H'));
        self::assertSame('2026-12-31', self::str($minusHours));
    }

    public function testDateTimeArithmetic(): void
    {
        $start = TemporalParser::dateAndTime('2027-01-01T10:00:00Z');
        $end = TemporalParser::dateAndTime('2027-01-02T12:30:00Z');

        self::assertSame('P1DT2H30M', (string) $end->minus($start));

        $twoHours = TemporalParser::duration('PT2H');
        \assert($twoHours instanceof DaysTimeDuration);
        self::assertSame('2027-01-01T12:00:00Z', (string) $start->plus($twoHours));
    }

    public function testZonedAndLocalDateTimesDoNotCompare(): void
    {
        $local = TemporalParser::dateAndTime('2027-01-01T10:00:00');
        $zoned = TemporalParser::dateAndTime('2027-01-01T10:00:00Z');

        $this->expectException(FeelError::class);

        $local->compareTo($zoned);
    }

    public function testOffsetComparisonAcrossZones(): void
    {
        $berlin = TemporalParser::dateAndTime('2027-06-01T12:00:00@Europe/Berlin'); // UTC+2 in June
        $utc = TemporalParser::dateAndTime('2027-06-01T10:00:00Z');

        self::assertSame(0, $berlin->compareTo($utc));
    }

    public function testTimeArithmeticWrapsAroundMidnight(): void
    {
        $time = TemporalParser::time('23:30:00');
        $shifted = $time->plus(DaysTimeDuration::ofSeconds(3_600));

        self::assertSame('00:30:00', (string) $shifted);
    }

    public function testDurationArithmetic(): void
    {
        $ymd = TemporalParser::duration('P1Y');
        \assert($ymd instanceof YearsMonthsDuration);

        self::assertSame('P2Y6M', self::str(Ops::multiply($ymd, FeelNumber::of('2.5'))));
        self::assertSame('2', self::str(Ops::divide(TemporalParser::duration('P2Y'), $ymd)));

        $dtd = TemporalParser::duration('PT90M');
        \assert($dtd instanceof DaysTimeDuration);

        self::assertSame('PT45M', self::str(Ops::divide($dtd, FeelNumber::of(2))));
        self::assertSame('1.5', self::str(Ops::divide($dtd, TemporalParser::duration('PT1H'))));
    }

    public function testNegativeYearDates(): void
    {
        $date = TemporalParser::date('-0044-03-15');

        self::assertSame(-44, $date->year);
        self::assertSame('-0044-03-15', (string) $date);
        self::assertSame(1, TemporalParser::date('0001-01-01')->compareTo($date));
    }
}
