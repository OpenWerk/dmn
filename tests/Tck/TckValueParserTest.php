<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Tck;

use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDate;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;
use OpenWerk\DecisionModelAndNotation\Feel\Value\YearsMonthsDuration;
use OpenWerk\DecisionModelAndNotation\Tck\TckTestCasesFile;
use OpenWerk\DecisionModelAndNotation\Tests\Support\ValueAssertions;
use PHPUnit\Framework\TestCase;

final class TckValueParserTest extends TestCase
{
    use ValueAssertions;

    private const string FIXTURE = __DIR__
        . '/../fixtures/tck-mini/compliance-level-2/0001-sample/0001-sample-test-01.xml';

    public function testParsesTestCasesFile(): void
    {
        $file = TckTestCasesFile::fromFile(self::FIXTURE);

        self::assertSame('0001-sample.dmn', $file->modelName);
        self::assertCount(2, $file->testCases);

        $first = $file->testCases[0];
        self::assertSame('001', $first->id);
        self::assertSame('Bob', $first->inputs['name']);

        $order = self::arr($first->inputs['order']);
        self::assertInstanceOf(FeelNumber::class, $order['total']);
        self::assertSame('120.5', self::str($order['total']));
        self::assertTrue($order['express']);
        self::assertNull($order['note']);

        $tags = self::arr($order['tags']);
        self::assertSame(['a', 'b'], $tags);

        self::assertInstanceOf(FeelDate::class, $first->inputs['since']);
        self::assertInstanceOf(YearsMonthsDuration::class, $first->inputs['membership']);

        self::assertCount(1, $first->expectations);
        self::assertSame('Greeting', $first->expectations[0]->decision);
        self::assertSame('Hi Bob', $first->expectations[0]->expected);
    }
}
