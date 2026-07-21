<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Engine;

use OpenWerk\DecisionModelAndNotation\Dmn;
use OpenWerk\DecisionModelAndNotation\DmnModel;
use PHPUnit\Framework\TestCase;

/**
 * Item definition `allowedValues` are enforced wherever declared types are
 * checked: a value outside the allowed values does not conform, so the
 * decision errors to null with a diagnostic.
 */
final class AllowedValuesTest extends TestCase
{
    public function testAllowedValuesAcceptListedValues(): void
    {
        $result = $this->model()->evaluateDecision('Size', ['s' => 'M']);

        self::assertFalse($result->hasErrors());
        self::assertSame('M', $result->value());
    }

    public function testViolationsErrorToNull(): void
    {
        $result = $this->model()->evaluateDecision('Size', ['s' => 'XXL']);

        self::assertTrue($result->hasErrors());
        self::assertNull($result->value());
    }

    public function testComponentConstraintsAreEnforced(): void
    {
        self::assertFalse($this->model()->evaluateDecision('Order', ['s' => 'L'])->hasErrors());

        $result = $this->model()->evaluateDecision('Order', ['s' => 'XXL']);

        self::assertTrue($result->hasErrors());
        self::assertNull($result->value());
    }

    public function testCollectionElementsAreConstrained(): void
    {
        self::assertFalse($this->model()->evaluateDecision('Sizes', ['s' => 'L'])->hasErrors());

        $result = $this->model()->evaluateDecision('Sizes', ['s' => 'XXL']);

        self::assertTrue($result->hasErrors());
        self::assertNull($result->value());
    }

    public function testRangeConstraintsApplyToNumbers(): void
    {
        self::assertFalse($this->model()->evaluateDecision('Score', ['n' => 100])->hasErrors());

        $result = $this->model()->evaluateDecision('Score', ['n' => 150]);

        self::assertTrue($result->hasErrors());
        self::assertNull($result->value());
    }

    public function testNullStillConforms(): void
    {
        // A null result is not an allowed-values violation — null keeps its
        // usual "conforms to everything" role in declared-type checks.
        $result = $this->model()->evaluateDecision('Size', []);

        self::assertNull($result->value());
        self::assertFalse($result->hasErrors());
    }

    public function testConstraintsSurviveTheCacheRoundTrip(): void
    {
        // allowedValues parse at model load; the parsed constraint must
        // travel through serialize()/unserialize() like any other FEEL text.
        $model = unserialize(serialize($this->model()));

        self::assertInstanceOf(DmnModel::class, $model);
        self::assertFalse($model->evaluateDecision('Size', ['s' => 'M'])->hasErrors());
        self::assertTrue($model->evaluateDecision('Size', ['s' => 'XXL'])->hasErrors());
    }

    public function testUnparseableConstraintNeverRejectsAndIsAValidationFinding(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <itemDefinition id="tBad" name="tBad">
                <typeRef>string</typeRef>
                <allowedValues><text>"S",,</text></allowedValues>
              </itemDefinition>
              <inputData id="sIn" name="s"><variable name="s" typeRef="string"/></inputData>
              <decision id="d1" name="Size">
                <variable name="Size" typeRef="tBad"/>
                <informationRequirement><requiredInput href="#sIn"/></informationRequirement>
                <literalExpression><text>s</text></literalExpression>
              </decision>
            </definitions>
            XML;

        $model = Dmn::fromString($xml);

        self::assertTrue($model->hasValidationErrors());

        // The broken constraint is reported, not silently enforced: any
        // string still conforms.
        $result = $model->evaluateDecision('Size', ['s' => 'XXL']);

        self::assertFalse($result->hasErrors());
        self::assertSame('XXL', $result->value());
    }

    private function model(): DmnModel
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <itemDefinition id="tSize" name="tSize">
                <typeRef>string</typeRef>
                <allowedValues><text>"S","M","L"</text></allowedValues>
              </itemDefinition>
              <itemDefinition id="tScore" name="tScore">
                <typeRef>number</typeRef>
                <allowedValues><text>[0..100]</text></allowedValues>
              </itemDefinition>
              <itemDefinition id="tOrder" name="tOrder">
                <itemComponent id="tOrderSize" name="size"><typeRef>tSize</typeRef></itemComponent>
                <itemComponent id="tOrderScore" name="score"><typeRef>tScore</typeRef></itemComponent>
              </itemDefinition>
              <itemDefinition id="tSizes" name="tSizes" isCollection="true"><typeRef>tSize</typeRef></itemDefinition>
              <inputData id="sIn" name="s"><variable name="s" typeRef="string"/></inputData>
              <inputData id="nIn" name="n"><variable name="n" typeRef="number"/></inputData>
              <decision id="d1" name="Size">
                <variable name="Size" typeRef="tSize"/>
                <informationRequirement><requiredInput href="#sIn"/></informationRequirement>
                <literalExpression><text>s</text></literalExpression>
              </decision>
              <decision id="d2" name="Order">
                <variable name="Order" typeRef="tOrder"/>
                <informationRequirement><requiredInput href="#sIn"/></informationRequirement>
                <literalExpression><text>{size: s, score: 50}</text></literalExpression>
              </decision>
              <decision id="d3" name="Sizes">
                <variable name="Sizes" typeRef="tSizes"/>
                <informationRequirement><requiredInput href="#sIn"/></informationRequirement>
                <literalExpression><text>["S", s]</text></literalExpression>
              </decision>
              <decision id="d4" name="Score">
                <variable name="Score" typeRef="tScore"/>
                <informationRequirement><requiredInput href="#nIn"/></informationRequirement>
                <literalExpression><text>n</text></literalExpression>
              </decision>
            </definitions>
            XML;

        return Dmn::fromString($xml);
    }
}
