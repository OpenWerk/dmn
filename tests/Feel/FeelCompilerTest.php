<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Feel;

use OpenWerk\DecisionModelAndNotation\Diagnostics\DiagnosticCollector;
use OpenWerk\DecisionModelAndNotation\Engine\InputConverter;
use OpenWerk\DecisionModelAndNotation\Feel\Evaluator\FeelEvaluator;
use OpenWerk\DecisionModelAndNotation\Feel\Evaluator\Scope;
use OpenWerk\DecisionModelAndNotation\Feel\Parser\FeelParser;
use OpenWerk\DecisionModelAndNotation\Feel\Parser\NameRegistry;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDate;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelFunction;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;
use PHPUnit\Framework\TestCase;

/**
 * The compiled evaluator caches closures per AST node; these tests guard
 * the cache against the failure modes a compiler introduces: stale values
 * across scopes, and compiled/interpreted nesting.
 */
final class FeelCompilerTest extends TestCase
{
    /**
     * @param array<string, mixed> $variables
     */
    private function value(string $expression, array $variables = [], ?NameRegistry $registry = null): mixed
    {
        $registry ??= NameRegistry::withBuiltins();

        foreach (array_keys($variables) as $name) {
            $registry->add($name);
        }

        $scope = [];

        foreach ($variables as $name => $variable) {
            $scope[$name] = InputConverter::toFeel($variable);
        }

        $node = (new FeelParser($registry))->parseExpression($expression);

        return (new FeelEvaluator(new DiagnosticCollector()))->evaluate($node, new Scope($scope));
    }

    public function testCompiledNodesReevaluateAgainstFreshScopes(): void
    {
        $node = (new FeelParser(NameRegistry::withBuiltins()))->parseExpression('x + y');
        $evaluator = new FeelEvaluator(new DiagnosticCollector());

        $first = $evaluator->evaluate($node, new Scope(['x' => FeelNumber::of(1), 'y' => FeelNumber::of(2)]));
        $second = $evaluator->evaluate($node, new Scope(['x' => FeelNumber::of(10), 'y' => FeelNumber::of(5)]));

        self::assertInstanceOf(FeelNumber::class, $first);
        self::assertSame('3', (string) $first);
        self::assertInstanceOf(FeelNumber::class, $second);
        self::assertSame('15', (string) $second);
    }

    public function testInterpreterFallbackNestsCompiledChildren(): void
    {
        // The if expression interprets (no dedicated compiler yet); its
        // condition and branches compile. Both directions must compose.
        $taken = $this->value('if x > 3 then x + 1 else 0', ['x' => 5]);
        $skipped = $this->value('if x > 3 then x + 1 else 0', ['x' => 1]);

        self::assertInstanceOf(FeelNumber::class, $taken);
        self::assertSame('6', (string) $taken);
        self::assertInstanceOf(FeelNumber::class, $skipped);
        self::assertSame('0', (string) $skipped);
    }

    public function testDottedNameWalksTheLongestBoundPrefix(): void
    {
        $registry = NameRegistry::withBuiltins();
        $registry->add('applicant.age');

        $value = $this->value('applicant.age', ['applicant' => ['age' => 34]], $registry);

        self::assertInstanceOf(FeelNumber::class, $value);
        self::assertSame('34', (string) $value);
    }

    public function testBareBuiltinNameYieldsAFunctionValueUnlessShadowed(): void
    {
        self::assertInstanceOf(FeelFunction::class, $this->value('abs'));

        $shadowed = $this->value('abs', ['abs' => 42]);

        self::assertInstanceOf(FeelNumber::class, $shadowed);
        self::assertSame('42', (string) $shadowed);
    }

    public function testNamedArgumentsBindThroughTheCompiledPlan(): void
    {
        self::assertSame('ello', $this->value('substring(string: "hello", start position: 2)'));
    }

    public function testVariadicBuiltinsBindByCount(): void
    {
        $value = $this->value('max(1, 5, 3)');

        self::assertInstanceOf(FeelNumber::class, $value);
        self::assertSame('5', (string) $value);
    }

    public function testArityMismatchReportsSignatureError(): void
    {
        $registry = NameRegistry::withBuiltins();
        $collector = new DiagnosticCollector();
        $node = (new FeelParser($registry))->parseExpression('abs(1, 2, 3)');

        $value = (new FeelEvaluator($collector))->evaluate($node, new Scope([]));

        self::assertNull($value);
        self::assertCount(1, $collector->all());
        self::assertStringContainsString('do not match any signature', $collector->all()[0]->message);
    }

    public function testUserDefinedFunctionsInvokeThroughCompiledCalls(): void
    {
        $value = $this->value('{f: function(a, b) a + b, r: f(2, 3)}.r');

        self::assertInstanceOf(FeelNumber::class, $value);
        self::assertSame('5', (string) $value);
    }

    /**
     * @return list<string>
     */
    private function numberStrings(mixed $value): array
    {
        self::assertIsArray($value);
        $strings = [];

        foreach ($value as $item) {
            self::assertInstanceOf(FeelNumber::class, $item);
            $strings[] = (string) $item;
        }

        return $strings;
    }

    public function testForExpressionsIterateWithPartial(): void
    {
        self::assertSame(['2', '4', '6'], $this->numberStrings($this->value('for i in 1..3 return i * 2')));
        self::assertSame(
            ['1', '3', '5'],
            $this->numberStrings($this->value('for i in [1, 2, 3] return i + count(partial)')),
        );
        self::assertSame(
            ['11', '21', '12', '22'],
            $this->numberStrings($this->value('for a in [1, 2], b in [10, 20] return a + b')),
        );
    }

    public function testQuantifiersCombineTernary(): void
    {
        self::assertTrue($this->value('some x in [1, 2, 3] satisfies x > 2'));
        self::assertTrue($this->value('every x in [1, 2, 3] satisfies x > 0'));
        self::assertFalse($this->value('every x in [1, 2, 3] satisfies x > 1'));
    }

    public function testFilterIndexAndPredicateSelection(): void
    {
        $second = $this->value('[10, 20, 30][2]');
        self::assertInstanceOf(FeelNumber::class, $second);
        self::assertSame('20', (string) $second);

        $last = $this->value('[10, 20, 30][-1]');
        self::assertInstanceOf(FeelNumber::class, $last);
        self::assertSame('30', (string) $last);

        self::assertSame(['20', '30'], $this->numberStrings($this->value('[10, 20, 30][item > 10]')));
    }

    public function testDuplicateContextKeysErrorAtTheDuplicateEntry(): void
    {
        $registry = NameRegistry::withBuiltins();
        $collector = new DiagnosticCollector();
        $node = (new FeelParser($registry))->parseExpression('{a: 1, a: 2}');

        $value = (new FeelEvaluator($collector))->evaluate($node, new Scope([]));

        self::assertNull($value);
        self::assertCount(1, $collector->all());
        self::assertStringContainsString('duplicate context key', $collector->all()[0]->message);
    }

    public function testInstanceOfAndBetweenCompile(): void
    {
        self::assertTrue($this->value('5 instance of number'));
        self::assertFalse($this->value('"x" instance of number'));
        self::assertTrue($this->value('5 between 1 and 10'));
        self::assertFalse($this->value('11 between 1 and 10'));
    }

    public function testAtLiteralsFoldAtCompileTime(): void
    {
        $date = $this->value('@ "2026-01-01"');

        self::assertInstanceOf(FeelDate::class, $date);
        self::assertSame('2026-01-01', (string) $date);
    }
}
