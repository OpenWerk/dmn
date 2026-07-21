<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Engine;

use Brick\Math\BigDecimal;
use OpenWerk\DecisionModelAndNotation\Diagnostics\Diagnostic;
use OpenWerk\DecisionModelAndNotation\Diagnostics\Severity;
use OpenWerk\DecisionModelAndNotation\Dmn;
use OpenWerk\DecisionModelAndNotation\Exception\UnknownDecisionException;
use OpenWerk\DecisionModelAndNotation\Tests\Support\ValueAssertions;
use PHPUnit\Framework\TestCase;

final class DrgEvaluationTest extends TestCase
{
    use ValueAssertions;

    private const string FIXTURES = __DIR__ . '/../fixtures';

    public function testEvaluatesRequirementChain(): void
    {
        $model = Dmn::fromFile(self::FIXTURES . '/fees.dmn');

        $result = $model->evaluateDecision('Discounted Fee', [
            'applicant' => ['age' => 34, 'income' => 51_000],
        ]);

        self::assertFalse($result->hasErrors());
        self::assertSame('Discounted Fee', $result->decisionName());

        $value = $result->value();
        self::assertInstanceOf(BigDecimal::class, $value);
        self::assertSame('90', self::str($result->feelValue()));

        // Explainability: the matched rules of every evaluated table.
        $matched = array_map(
            static fn($rule): string => $rule->decision . '#' . $rule->ruleIndex,
            $result->matchedRules(),
        );
        self::assertSame(['Risk Category#3', 'Fee#1'], $matched);
    }

    public function testIntermediateDecisionsAreDirectlyEvaluatable(): void
    {
        $model = Dmn::fromFile(self::FIXTURES . '/fees.dmn');

        $risk = $model->evaluateDecision('Risk Category', [
            'applicant' => ['age' => 20, 'income' => 10_000],
        ]);

        self::assertSame('high', $risk->feelValue());
    }

    public function testEvaluationByDecisionId(): void
    {
        $model = Dmn::fromFile(self::FIXTURES . '/fees.dmn');

        $result = $model->evaluateDecision('riskCategory', [
            'applicant' => ['age' => 70, 'income' => 10_000],
        ]);

        self::assertSame('medium', $result->feelValue());
    }

    public function testUnknownDecisionThrows(): void
    {
        $model = Dmn::fromFile(self::FIXTURES . '/fees.dmn');

        $this->expectException(UnknownDecisionException::class);
        $this->expectExceptionMessageMatches('/unknown decision/');

        $model->evaluateDecision('Nope');
    }

    public function testMissingInputYieldsNullResultWithWarning(): void
    {
        $model = Dmn::fromFile(self::FIXTURES . '/fees.dmn');

        $result = $model->evaluateDecision('Risk Category');

        self::assertNull($result->feelValue());

        $warnings = array_filter(
            $result->diagnostics(),
            static fn(Diagnostic $d): bool => $d->code === Diagnostic::DECISION_MISSING_INPUT
                && $d->severity === Severity::Warning,
        );
        self::assertNotEmpty($warnings);
    }

    public function testCollectSumFixtureAcrossNamespace13(): void
    {
        $model = Dmn::fromFile(self::FIXTURES . '/discounts.dmn');

        self::assertSame([], $model->validationMessages());

        $result = $model->evaluateDecision('Total Discount', [
            'order' => ['total' => 150, 'items' => 12],
        ]);

        self::assertSame('9', self::str($result->feelValue()));
        self::assertCount(3, $result->matchedRules());
    }

    public function testPriorityAndDefaultFixtureAcrossNamespace12(): void
    {
        $model = Dmn::fromFile(self::FIXTURES . '/ratings.dmn');

        self::assertSame([], $model->validationMessages());

        // score 350 matches all three rules; "decline" has highest priority
        self::assertSame('decline', $model->evaluateDecision('Rating', ['score' => 350])->feelValue());
        self::assertSame('refer', $model->evaluateDecision('Rating', ['score' => 500])->feelValue());
        self::assertSame('approve', $model->evaluateDecision('Rating', ['score' => 700])->feelValue());

        // FIRST table without a match falls back to its default output.
        self::assertSame('none', $model->evaluateDecision('Fallback', ['score' => 350])->feelValue());
        self::assertSame('elite', $model->evaluateDecision('Fallback', ['score' => 2_000])->feelValue());
    }

    public function testDiagnosticsAreTaggedWithDecisionNames(): void
    {
        $model = Dmn::fromFile(self::FIXTURES . '/fees.dmn');

        $result = $model->evaluateDecision('Discounted Fee');

        self::assertNotEmpty($result->diagnostics());

        foreach ($result->diagnostics() as $diagnostic) {
            self::assertNotNull($diagnostic->decision);
        }
    }

    public function testEvaluationIsDeterministicAndRepeatable(): void
    {
        $model = Dmn::fromFile(self::FIXTURES . '/fees.dmn');
        $inputs = ['applicant' => ['age' => 34, 'income' => 51_000]];

        $first = $model->evaluateDecision('Discounted Fee', $inputs);
        $second = $model->evaluateDecision('Discounted Fee', $inputs);

        self::assertSame(self::str($first->feelValue()), self::str($second->feelValue()));
        self::assertEquals($first->matchedRules(), $second->matchedRules());
    }
}
