<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Engine;

use OpenWerk\DecisionModelAndNotation\Dmn;
use OpenWerk\DecisionModelAndNotation\DmnModel;
use PHPUnit\Framework\TestCase;

/**
 * The DMN 1.4+ boxed conditional and quantified expressions are strict
 * about booleans: a non-boolean condition or satisfies outcome errors to
 * null (TCK 1150-boxed-conditional case 003, 1153-boxed-some case 006),
 * while null satisfies outcomes keep their ternary-logic meaning.
 */
final class BoxedStrictnessTest extends TestCase
{
    public function testConditionalPicksBranchesForBooleans(): void
    {
        $model = $this->conditionalModel('true');
        self::assertSame('then', $model->evaluateDecision('D')->value());

        $model = $this->conditionalModel('false');
        self::assertSame('else', $model->evaluateDecision('D')->value());
    }

    public function testConditionalWithNonBooleanConditionErrorsToNull(): void
    {
        $result = $this->conditionalModel('"abc"')->evaluateDecision('D');

        self::assertNull($result->value());
        self::assertTrue($result->hasErrors());
    }

    public function testConditionalWithNullConditionErrorsToNull(): void
    {
        $result = $this->conditionalModel('null')->evaluateDecision('D');

        self::assertNull($result->value());
        self::assertTrue($result->hasErrors());
    }

    public function testSomeWithNonBooleanSatisfiesErrorsToNull(): void
    {
        $result = $this->someModel('if (element = 2) then true else "not a boolean"')->evaluateDecision('D');

        self::assertNull($result->value());
        self::assertTrue($result->hasErrors());
    }

    public function testSomeKeepsTernaryNullSatisfies(): void
    {
        // null satisfies outcomes are unknowns, not errors: some(null, null)
        // is null without any error diagnostic.
        $result = $this->someModel('null')->evaluateDecision('D');

        self::assertNull($result->value());
        self::assertFalse($result->hasErrors());
    }

    public function testSomeStillShortCircuitsOnBooleans(): void
    {
        $result = $this->someModel('element = 2')->evaluateDecision('D');

        self::assertTrue($result->value());
        self::assertFalse($result->hasErrors());
    }

    private function conditionalModel(string $condition): DmnModel
    {
        $xml = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <decision id="d" name="D">
                <variable name="D"/>
                <conditional>
                  <if><literalExpression><text>$condition</text></literalExpression></if>
                  <then><literalExpression><text>"then"</text></literalExpression></then>
                  <else><literalExpression><text>"else"</text></literalExpression></else>
                </conditional>
              </decision>
            </definitions>
            XML;

        return Dmn::fromString($xml);
    }

    private function someModel(string $satisfies): DmnModel
    {
        $xml = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <decision id="d" name="D">
                <variable name="D"/>
                <some iteratorVariable="element">
                  <in><literalExpression><text>[1,2]</text></literalExpression></in>
                  <satisfies><literalExpression><text>$satisfies</text></literalExpression></satisfies>
                </some>
              </decision>
            </definitions>
            XML;

        return Dmn::fromString($xml);
    }
}
