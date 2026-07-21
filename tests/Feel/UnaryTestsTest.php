<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Feel;

use OpenWerk\DecisionModelAndNotation\Diagnostics\DiagnosticCollector;
use OpenWerk\DecisionModelAndNotation\Engine\InputConverter;
use OpenWerk\DecisionModelAndNotation\Feel\Evaluator\FeelEvaluator;
use OpenWerk\DecisionModelAndNotation\Feel\Evaluator\Scope;
use OpenWerk\DecisionModelAndNotation\Feel\Parser\FeelParser;
use OpenWerk\DecisionModelAndNotation\Feel\Parser\NameRegistry;
use OpenWerk\DecisionModelAndNotation\Feel\Value\TemporalParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnaryTestsTest extends TestCase
{
    /**
     * @param array<string, mixed> $scope
     */
    private function test(string $tests, mixed $input, array $scope = []): ?bool
    {
        $registry = NameRegistry::withBuiltins();

        foreach (array_keys($scope) as $name) {
            $registry->add((string) $name);
        }

        $feelScope = [];

        foreach ($scope as $name => $value) {
            $feelScope[(string) $name] = InputConverter::toFeel($value);
        }

        $evaluator = new FeelEvaluator(new DiagnosticCollector());
        $ast = (new FeelParser($registry))->parseUnaryTests($tests);

        return $evaluator->test($ast, InputConverter::toFeel($input), new Scope($feelScope));
    }

    #[DataProvider('cases')]
    public function testUnaryTestSemantics(string $tests, mixed $input, ?bool $expected): void
    {
        self::assertSame($expected, $this->test($tests, $input));
    }

    /**
     * @return iterable<string, array{string, mixed, ?bool}>
     */
    public static function cases(): iterable
    {
        yield 'dash matches anything' => ['-', 42, true];
        yield 'dash matches null' => ['-', null, true];
        yield 'equality match' => ['42', 42, true];
        yield 'equality mismatch' => ['42', 41, false];
        yield 'string equality' => ['"high"', 'high', true];
        yield 'comparison lt' => ['< 25', 24, true];
        yield 'comparison lt fails' => ['< 25', 25, false];
        yield 'comparison le' => ['<= 25', 25, true];
        yield 'comparison ge' => ['>= 25', 25, true];
        yield 'comparison with null input' => ['< 25', null, null];
        yield 'closed interval includes bounds' => ['[25..60]', 25, true];
        yield 'open interval excludes bounds' => ['(25..60)', 25, false];
        yield 'reversed bracket interval' => [']25..60[', 60, false];
        yield 'half open interval' => ['(25..60]', 60, true];
        yield 'list of tests is an or' => ['1, 2, 3', 2, true];
        yield 'list of tests no match' => ['1, 2, 3', 4, false];
        yield 'mixed comparisons in list' => ['< 10, > 90', 95, true];
        yield 'negation' => ['not("red", "green")', 'blue', true];
        yield 'negation no match' => ['not("red", "green")', 'red', false];
        yield 'null literal test on null input' => ['null', null, true];
        yield 'null literal test on value' => ['null', 5, false];
        yield 'boolean literal' => ['true', true, true];
        yield 'date interval' => ['[date("2027-01-01")..date("2027-12-31")]', null, null];
        yield 'string against number comparison is null' => ['< 25', 'abc', null];
    }

    public function testDateIntervalWithDateInput(): void
    {
        $input = TemporalParser::date('2027-06-15');

        self::assertTrue($this->test('[date("2027-01-01")..date("2027-12-31")]', $input));
    }

    public function testEndpointsCanReferenceScope(): void
    {
        self::assertTrue($this->test('>= minimum age', 21, ['minimum age' => 18]));
        self::assertFalse($this->test('>= minimum age', 17, ['minimum age' => 18]));
    }

    public function testListValuedExpressionTestsMembership(): void
    {
        self::assertTrue($this->test('allowed', 'b', ['allowed' => ['a', 'b']]));
        self::assertFalse($this->test('allowed', 'z', ['allowed' => ['a', 'b']]));
    }
}
