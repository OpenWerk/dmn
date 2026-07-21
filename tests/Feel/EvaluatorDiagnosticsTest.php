<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Feel;

use OpenWerk\DecisionModelAndNotation\Diagnostics\Diagnostic;
use OpenWerk\DecisionModelAndNotation\Diagnostics\DiagnosticCollector;
use OpenWerk\DecisionModelAndNotation\Feel\Evaluator\FeelEvaluator;
use OpenWerk\DecisionModelAndNotation\Feel\Evaluator\Scope;
use OpenWerk\DecisionModelAndNotation\Feel\Parser\FeelParser;
use OpenWerk\DecisionModelAndNotation\Feel\Parser\NameRegistry;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for the evaluator's diagnostic behavior: which codes are
 * emitted, in which order, and how often. The compiled evaluator (see the
 * TODO milestone) must reproduce this exactly; these tests pin it.
 */
final class EvaluatorDiagnosticsTest extends TestCase
{
    /**
     * @param array<string, mixed> $variables
     *
     * @return array{mixed, list<string>} value plus the emitted diagnostic codes
     */
    private function evaluate(string $expression, array $variables = []): array
    {
        $registry = NameRegistry::withBuiltins();

        foreach (array_keys($variables) as $name) {
            $registry->add($name);
        }

        $collector = new DiagnosticCollector();
        $node = (new FeelParser($registry))->parseExpression($expression);
        $value = (new FeelEvaluator($collector))->evaluate($node, new Scope($variables));

        $codes = array_map(
            static fn(Diagnostic $diagnostic): string => $diagnostic->code,
            $collector->all(),
        );

        return [$value, $codes];
    }

    public function testUnknownNameEmitsExactlyOneDiagnostic(): void
    {
        [$value, $codes] = $this->evaluate('nope');

        self::assertNull($value);
        self::assertSame([Diagnostic::FEEL_UNKNOWN_NAME], $codes);
    }

    public function testDiagnosticsFollowEvaluationOrderLeftToRight(): void
    {
        [$value, $codes] = $this->evaluate('alpha + beta');

        self::assertNull($value);
        self::assertSame([Diagnostic::FEEL_UNKNOWN_NAME, Diagnostic::FEEL_UNKNOWN_NAME], $codes);
    }

    public function testArithmeticTypeMismatchReportsFeelError(): void
    {
        [$value, $codes] = $this->evaluate('1 + "a"');

        self::assertNull($value);
        self::assertSame([Diagnostic::FEEL_ERROR], $codes);
    }

    public function testDivisionByZeroReportsFeelError(): void
    {
        [$value, $codes] = $this->evaluate('1 / 0');

        self::assertNull($value);
        self::assertSame([Diagnostic::FEEL_ERROR], $codes);
    }

    public function testUnknownFunctionKeepsItsDedicatedCode(): void
    {
        [$value, $codes] = $this->evaluate('frobnicate(1)');

        self::assertNull($value);
        self::assertSame([Diagnostic::FEEL_UNKNOWN_FUNCTION], $codes);
    }

    public function testComparingIncomparableKindsReportsFeelError(): void
    {
        [$value, $codes] = $this->evaluate('1 < "a"');

        self::assertNull($value);
        self::assertSame([Diagnostic::FEEL_ERROR], $codes);
    }

    public function testNonBooleanIfConditionPicksTheElseBranchSilently(): void
    {
        // Behavior pin, not a spec statement: the engine treats any
        // condition that is not `true` as false, without a diagnostic.
        [$value, $codes] = $this->evaluate('if 5 then "a" else "b"');

        self::assertSame('b', $value);
        self::assertSame([], $codes);
    }

    public function testFailingAtLiteralDiagnosesOnEveryEvaluationOfTheSameNode(): void
    {
        $node = (new FeelParser(NameRegistry::withBuiltins()))->parseExpression('@ "not a temporal"');

        foreach ([1, 2] as $round) {
            $collector = new DiagnosticCollector();
            $value = (new FeelEvaluator($collector))->evaluate($node, new Scope([]));

            self::assertNull($value, "round $round");
            self::assertCount(1, $collector->all(), "round $round");
            self::assertSame(Diagnostic::FEEL_ERROR, $collector->all()[0]->code, "round $round");
        }
    }

    public function testRunsOverTheSameNodeStayIsolated(): void
    {
        // One parsed node, two evaluation runs: each run's collector only
        // ever sees its own findings. The compiled evaluator caches per
        // node and must not leak run state through that cache.
        $node = (new FeelParser(NameRegistry::withBuiltins()))->parseExpression('nope');

        $first = new DiagnosticCollector();
        (new FeelEvaluator($first))->evaluate($node, new Scope([]));

        $second = new DiagnosticCollector();
        (new FeelEvaluator($second))->evaluate($node, new Scope([]));

        self::assertCount(1, $first->all());
        self::assertCount(1, $second->all());
    }

    public function testUnaryTestComparisonErrorReportsAndDoesNotMatch(): void
    {
        $collector = new DiagnosticCollector();
        $tests = (new FeelParser(NameRegistry::withBuiltins()))->parseUnaryTests('< "a"');

        $outcome = (new FeelEvaluator($collector))->test($tests, FeelNumber::of(1), new Scope([]));

        self::assertNull($outcome);
        self::assertSame(
            [Diagnostic::FEEL_ERROR],
            array_map(static fn(Diagnostic $diagnostic): string => $diagnostic->code, $collector->all()),
        );
    }

    public function testMutedCollectorDropsEverything(): void
    {
        $muted = DiagnosticCollector::muted();
        $muted->error(Diagnostic::FEEL_ERROR, 'dropped');
        $muted->warning(Diagnostic::FEEL_ERROR, 'dropped');
        $muted->info(Diagnostic::TABLE_NO_MATCH, 'dropped');

        self::assertSame([], $muted->all());
    }

    public function testFilterProbeLeaksNoDiagnostics(): void
    {
        // The numeric-filter probe evaluates the condition in the outer
        // scope; its failures must not surface in the run's diagnostics.
        [$value, $codes] = $this->evaluate('[{a: 1}, {a: 2}][a = 2]');

        self::assertSame([], $codes);
        self::assertIsArray($value);
        self::assertCount(1, $value);

        $selected = $value[0] ?? null;
        self::assertIsArray($selected);
        self::assertInstanceOf(FeelNumber::class, $selected['a'] ?? null);
        self::assertSame('2', (string) $selected['a']);
    }
}
