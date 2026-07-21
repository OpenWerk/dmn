<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Feel;

use OpenWerk\DecisionModelAndNotation\Diagnostics\Diagnostic;
use OpenWerk\DecisionModelAndNotation\Diagnostics\DiagnosticCollector;
use OpenWerk\DecisionModelAndNotation\Engine\InputConverter;
use OpenWerk\DecisionModelAndNotation\Feel\Evaluator\FeelEvaluator;
use OpenWerk\DecisionModelAndNotation\Feel\Evaluator\Scope;
use OpenWerk\DecisionModelAndNotation\Feel\Parser\FeelParser;
use OpenWerk\DecisionModelAndNotation\Feel\Parser\NameRegistry;
use OpenWerk\DecisionModelAndNotation\Feel\Value\DaysTimeDuration;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDate;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelFunction;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;
use OpenWerk\DecisionModelAndNotation\Tests\Support\ValueAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvaluatorTest extends TestCase
{
    use ValueAssertions;

    /**
     * @param array<string, mixed> $scope
     *
     * @return array{mixed, list<Diagnostic>}
     */
    private function evaluate(string $expression, array $scope = []): array
    {
        $registry = NameRegistry::withBuiltins();

        foreach (array_keys($scope) as $name) {
            $registry->add((string) $name);
        }

        $feelScope = [];

        foreach ($scope as $name => $value) {
            $feelScope[(string) $name] = InputConverter::toFeel($value);
        }

        $collector = new DiagnosticCollector();
        $evaluator = new FeelEvaluator($collector);
        $node = (new FeelParser($registry))->parseExpression($expression);

        return [$evaluator->evaluate($node, new Scope($feelScope)), $collector->all()];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function value(string $expression, array $scope = []): mixed
    {
        [$value] = $this->evaluate($expression, $scope);

        return $value;
    }

    #[DataProvider('expressions')]
    public function testEvaluatesExpressions(string $expression, mixed $expected): void
    {
        $value = $this->value($expression);

        if ($value instanceof FeelNumber) {
            $value = (string) $value;
        }

        self::assertSame($expected, $value);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function expressions(): iterable
    {
        yield 'arithmetic precedence' => ['1 + 2 * 3', '7'];
        yield 'parentheses' => ['(1 + 2) * 3', '9'];
        yield 'division' => ['7 / 2', '3.5'];
        yield 'power left assoc' => ['2 ** 3 ** 2', '64'];
        yield 'negated base of power' => ['-2 ** 2', '4'];
        yield 'decimal arithmetic exact' => ['0.1 + 0.2', '0.3'];
        yield 'comparison true' => ['3 > 2', true];
        yield 'comparison false' => ['"a" > "b"', false];
        yield 'string equality' => ['"a" = "a"', true];
        yield 'inequality' => ['1 != 2', true];
        yield 'and short circuit' => ['false and (1/0 = 1)', false];
        yield 'or short circuit' => ['true or (1/0 = 1)', true];
        yield 'ternary and with null' => ['true and null', null];
        yield 'ternary or with null' => ['false or null', null];
        yield 'null equality' => ['null = null', true];
        yield 'null comparison yields null' => ['1 < null', null];
        yield 'between' => ['5 between 1 and 10', true];
        yield 'between excluded' => ['0 between 1 and 10', false];
        yield 'not function' => ['not(true)', false];
        yield 'not of null' => ['not(null)', null];
        yield 'if then else' => ['if 3 > 2 then "yes" else "no"', 'yes'];
        yield 'if with non-true condition takes else' => ['if null then 1 else 2', '2'];
        yield 'quantified some' => ['some x in [1, 2, 3] satisfies x > 2', true];
        yield 'quantified every' => ['every x in [1, 2, 3] satisfies x > 2', false];
        yield 'context literal entries see earlier entries' => ['{a: 2, b: a * 3}.b', '6'];
        yield 'filter by index' => ['[10, 20, 30][2]', '20'];
        yield 'filter by negative index' => ['[10, 20, 30][-1]', '30'];
        yield 'in operator with range' => ['5 in [1..10]', true];
        yield 'in operator with test list' => ['5 in (<2, >4)', true];
        yield 'range equality' => ['[1..3] = [1..3]', true];
        yield 'range constructor' => ['range("[1..3]") = [1..3]', true];
        yield 'instance of number' => ['1 instance of number', true];
        yield 'instance of list of numbers' => ['[1, 2] instance of list<number>', true];
        yield 'null is not Any' => ['null instance of Any', false];
        yield 'lambda invocation' => ['(function(x) x * 2)(21)', '42'];
        yield 'named arguments' => ['substring(string: "foobar", start position: 3)', 'obar'];
        yield 'upper case' => ['upper case("abc4")', 'ABC4'];
        yield 'sum' => ['sum([1, 2, 3])', '6'];
        yield 'decimal' => ['decimal(1/3, 2)', '0.33'];
        yield 'modulo follows divisor sign' => ['modulo(12, -5)', '-3'];
        yield 'day of week' => ['day of week(date("2026-07-15"))', 'Wednesday'];
        yield 'context put' => ['context put({a: 1}, "b", 2).b', '2'];
        yield 'range before' => ['before(1, [2..5])', true];
        yield 'string join' => ['string join(["a", null, "b"], "-")', 'a-b'];
        yield 'astral string length' => ['string length("\U01F40E")', '1'];
        yield 'date comparison' => ['date("2027-01-01") < date("2027-06-01")', true];
        yield 'date equality via at literal' => ['@"2027-01-01" = date("2027-01-01")', true];
        yield 'duration comparison' => ['duration("PT1H") < duration("P1D")', true];
        yield 'temporal arithmetic' => ['date("2027-01-31") + duration("P1M") = date("2027-02-28")', true];
        yield 'string concatenation' => ['"Hello " + "John Doe"', 'Hello John Doe'];
        yield 'string plus number is an error -> null' => ['"a" + 1', null];
        yield 'mixed kind equality is null' => ['1 = "1"', null];
        yield 'value against null is false' => ['"foo" = null', false];
    }

    public function testErrorsEvaluateToNullWithDiagnostic(): void
    {
        [$value, $diagnostics] = $this->evaluate('1 + "a"');

        self::assertNull($value);
        self::assertNotEmpty($diagnostics);
        self::assertSame(Diagnostic::FEEL_ERROR, $diagnostics[0]->code);
    }

    public function testDivisionByZeroYieldsNullAndDiagnostic(): void
    {
        [$value, $diagnostics] = $this->evaluate('1 / 0');

        self::assertNull($value);
        self::assertSame(Diagnostic::FEEL_ERROR, $diagnostics[0]->code);
        self::assertStringContainsString('division by zero', $diagnostics[0]->message);
    }

    public function testUnknownNameYieldsNullAndDiagnostic(): void
    {
        [$value, $diagnostics] = $this->evaluate('nope + 1');

        self::assertNull($value);
        self::assertSame(Diagnostic::FEEL_UNKNOWN_NAME, $diagnostics[0]->code);
    }

    public function testUnknownFunctionYieldsNullAndDiagnostic(): void
    {
        [$value, $diagnostics] = $this->evaluate('frobnicate(1)');

        self::assertNull($value);
        self::assertSame(Diagnostic::FEEL_UNKNOWN_FUNCTION, $diagnostics[0]->code);
    }

    public function testConstantBuiltinCallsMemoizePerNodeButRespectShadowing(): void
    {
        // The same AST node evaluates repeatedly in table matching; constant
        // conversion calls memoize per node. A function bound in scope under
        // the builtin's name shadows it and must bypass the memo.
        $node = (new FeelParser(NameRegistry::withBuiltins()))->parseExpression('duration("PT1H")');

        $constant = (new FeelEvaluator(new DiagnosticCollector()))->evaluate($node, new Scope([]));
        self::assertInstanceOf(DaysTimeDuration::class, $constant);
        self::assertSame('PT1H', (string) $constant);

        $shadow = new FeelFunction(['s'], static fn(array $arguments): mixed => 'shadowed');
        $shadowed = (new FeelEvaluator(new DiagnosticCollector()))
            ->evaluate($node, new Scope(['duration' => $shadow]));

        self::assertSame('shadowed', $shadowed);

        $again = (new FeelEvaluator(new DiagnosticCollector()))->evaluate($node, new Scope([]));
        self::assertInstanceOf(DaysTimeDuration::class, $again);
        self::assertSame('PT1H', (string) $again);
    }

    public function testScopeLookupAndPaths(): void
    {
        $scope = ['applicant' => ['age' => 34, 'address' => ['city' => 'Berlin']]];

        $age = $this->value('applicant.age', $scope);
        self::assertInstanceOf(FeelNumber::class, $age);
        self::assertSame('34', (string) $age);

        self::assertSame('Berlin', $this->value('applicant.address.city', $scope));
        self::assertNull($this->value('applicant.address.zip', $scope));
    }

    public function testPathProjectionOverList(): void
    {
        $scope = ['orders' => [['amount' => 10], ['amount' => 20]]];

        $strings = [];

        foreach (self::arr($this->value('orders.amount', $scope)) as $item) {
            $strings[] = self::str($item);
        }

        self::assertSame(['10', '20'], $strings);
    }

    public function testNamesWithSpacesResolve(): void
    {
        $value = $this->value('monthly salary * 12', ['monthly salary' => 4250]);

        self::assertInstanceOf(FeelNumber::class, $value);
        self::assertSame('51000', (string) $value);
    }

    public function testDateBuiltinWithThreeArguments(): void
    {
        $value = $this->value('date(2027, 1, 31)');

        self::assertInstanceOf(FeelDate::class, $value);
        self::assertSame('2027-01-31', (string) $value);
    }

    public function testDateAndTimeFromDateAndTimeParts(): void
    {
        $value = $this->value('date and time(date("2027-01-01"), time("10:00:00Z"))');

        self::assertSame('2027-01-01T10:00:00Z', self::str($value));
    }

    public function testInvalidTemporalLiteralYieldsNull(): void
    {
        [$value, $diagnostics] = $this->evaluate('date("not a date")');

        self::assertNull($value);
        self::assertNotEmpty($diagnostics);
    }

    public function testAllAndAnyWithoutArgumentsError(): void
    {
        [$all, $allDiagnostics] = $this->evaluate('all()');
        [$any, $anyDiagnostics] = $this->evaluate('any()');

        self::assertNull($all);
        self::assertNotEmpty($allDiagnostics);
        self::assertNull($any);
        self::assertNotEmpty($anyDiagnostics);

        // With an (empty) list argument they keep their vacuous truths.
        self::assertTrue($this->value('all([])'));
        self::assertFalse($this->value('any([])'));
    }

    public function testDecimalTruncatesFractionalScale(): void
    {
        self::assertSame('0.33', self::str($this->value('decimal(1/3, 2.5)')));
        self::assertSame('0.33', self::str($this->value('decimal(1/3, 2)')));
    }

    public function testFloorKeepsStrictIntegerScale(): void
    {
        [$value, $diagnostics] = $this->evaluate('floor(1.55, 1.5)');

        self::assertNull($value);
        self::assertNotEmpty($diagnostics);
    }

    public function testSubstringTruncatesFractionalLength(): void
    {
        self::assertSame('oba', $this->value('substring("foobar", 3, 3.8)'));
    }

    public function testDurationAllowsEmptySecondsFraction(): void
    {
        self::assertSame('PT0S', self::str($this->value('duration("PT0.S")')));
        self::assertSame('PT1.5S', self::str($this->value('duration("PT1.5S")')));
    }

    public function testFilterPrefersTheItemMemberOverTheImplicitBinding(): void
    {
        $value = $this->value('[{item: 1}, {item: 2}, {item: 3}][item >= 2]');

        self::assertIsArray($value);
        self::assertSame(['2', '3'], array_map(
            static fn(mixed $context): string => \is_array($context) ? self::str($context['item'] ?? null) : '?',
            $value,
        ));

        // Without an `item` member, `item` keeps meaning the whole element.
        self::assertSame([3, 4], array_map(
            static fn(mixed $n): int => (int) self::str($n),
            (array) $this->value('[1, 2, 3, 4][item > 2]'),
        ));
    }

    public function testListMembershipTreatsCrossKindMismatchAsNonMember(): void
    {
        [$value, $diagnostics] = $this->evaluate('true in [false, 2, 3]');

        self::assertFalse($value);
        self::assertSame([], $diagnostics);

        self::assertTrue($this->value('true in [2, true]'));
        self::assertTrue($this->value('5 in [null, 5]'));
    }

    public function testEqualityUnaryTestIsRangeKind(): void
    {
        self::assertTrue($this->value('(=10).start included'));
        self::assertTrue($this->value('(=10).end included'));
        self::assertSame('10', self::str($this->value('(=10).start')));
        self::assertSame('10', self::str($this->value('(=10).end')));

        // A negated equality test has no range endpoints.
        [$value, $diagnostics] = $this->evaluate('(!=10).start');
        self::assertNull($value);
        self::assertNotEmpty($diagnostics);
    }

    public function testBuiltinsAreFirstClassValues(): void
    {
        self::assertSame('625', self::str($this->value('{f: abs, r: f(-625)}.r')));
        self::assertSame('25', self::str($this->value('{f: sqrt, r: f(625)}.r')));

        // Scope names shadow builtins.
        self::assertSame('shadowed', $this->value('abs', ['abs' => 'shadowed']));
    }

    public function testInstanceOfStaysStrictAboutNullListElements(): void
    {
        self::assertFalse($this->value('[null] instance of list<string>'));
        self::assertTrue($this->value('["a"] instance of list<string>'));
    }

    public function testMatchesUsesTheXsdRegexDialect(): void
    {
        // XSD's dot matches neither carriage return nor newline...
        self::assertFalse($this->value('matches("Mary\u000DJones", "Mary.Jones")'));
        self::assertTrue($this->value('matches("Mary Jones", "Mary.Jones")'));
        // ...unless the s flag turns it into "any character".
        self::assertTrue($this->value('matches("Mary\nJones", "Mary.Jones", "s")'));

        // Character-class subtraction, caseless included.
        self::assertTrue($this->value('matches("x", "[A-Z-[OI]]", "i")'));
        self::assertFalse($this->value('matches("o", "[A-Z-[OI]]", "i")'));
        self::assertFalse($this->value('matches("O", "[A-Z-[OI]]")'));
        self::assertTrue($this->value('matches("Q", "[A-Z-[OI]]")'));

        // The x flag strips pattern whitespace before interpretation.
        self::assertTrue($this->value('matches("hello world", "hello\ sworld", "x")'));
        self::assertTrue($this->value('matches("hello world", "\p{ I s B a s i c L a t i n }+", "x")'));

        // XSD block escapes translate to codepoint ranges.
        self::assertTrue($this->value('matches("hello", "^\p{IsBasicLatin}+$")'));
        self::assertFalse($this->value('matches("héllo", "^\p{IsBasicLatin}+$")'));
    }

    public function testMatchesRejectsEscapesTheXsdDialectForbids(): void
    {
        $invalid = [
            'matches("abcd", "(asd)[\1]")',
            'matches("abcd", "[asd\0]")',
            'matches("a", "\0")',
        ];

        foreach ($invalid as $expression) {
            [$value, $diagnostics] = $this->evaluate($expression);

            self::assertNull($value, $expression);
            self::assertNotEmpty($diagnostics, $expression);
        }
    }

    public function testReplaceAndSplitShareTheXsdDialect(): void
    {
        self::assertSame('aXc', $this->value('replace("abc", "b", "X")'));
        self::assertSame('[h] [w]', $this->value('replace("hello world", "([a-z])[a-z]*", "[$1]")'));
        self::assertSame(['a', 'b', 'c'], $this->value('split("a;b;c", ";")'));

        // The dot dialect applies to replace too: "." leaves the \r alone.
        self::assertSame("X\rX", $this->value('replace("a\rb", ".", "X")'));
    }

    public function testForLoopCollectsResultsWithPartial(): void
    {
        $value = $this->value('for i in 0..4 return if i = 0 then 1 else i * partial[-1]');

        self::assertIsArray($value);
        self::assertSame(['1', '1', '2', '6', '24'], array_map(self::str(...), $value));
    }

    public function testForLoopOverDates(): void
    {
        $value = $this->value('for d in @"1980-01-01"..@"1980-01-03" return d');

        self::assertIsArray($value);
        self::assertSame(['1980-01-01', '1980-01-02', '1980-01-03'], array_map(self::str(...), $value));
    }

    public function testFilterByPredicateBindsItemAndMembers(): void
    {
        $scope = ['orders' => [['amount' => 10], ['amount' => 25]]];
        $value = $this->value('orders[amount > 20].amount', $scope);

        self::assertIsArray($value);
        self::assertSame(['25'], array_map(self::str(...), $value));
    }

    public function testCartesianForLoop(): void
    {
        $value = $this->value('for x in [1, 2], y in [10, 20] return x + y');

        self::assertIsArray($value);
        self::assertSame(['11', '21', '12', '22'], array_map(self::str(...), $value));
    }

    public function testSortWithPrecedesFunction(): void
    {
        $value = $this->value('sort([3, 1, 2], function(a, b) a > b)');

        self::assertIsArray($value);
        self::assertSame(['3', '2', '1'], array_map(self::str(...), $value));
    }
}
