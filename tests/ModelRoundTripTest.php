<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests;

use Brick\Math\BigDecimal;
use OpenWerk\DecisionModelAndNotation\Dmn;
use OpenWerk\DecisionModelAndNotation\DmnModel;
use PHPUnit\Framework\TestCase;

/**
 * The caching guarantee: a parsed model survives serialize()/unserialize()
 * unchanged — same validation findings, same introspection, same evaluation
 * behavior. Applications rely on this to parse once and evaluate from a
 * cache (see README "serialize once, evaluate everywhere").
 */
final class ModelRoundTripTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/fixtures';

    public function testFixtureModelSurvivesTheRoundTrip(): void
    {
        $model = Dmn::fromFile(self::FIXTURES . '/fees.dmn');
        $cached = $this->roundTrip($model);

        self::assertSame($model->name(), $cached->name());
        self::assertSame($model->decisionNames(), $cached->decisionNames());
        self::assertEquals($model->validationMessages(), $cached->validationMessages());
        self::assertEquals($model->invocables(), $cached->invocables());
    }

    public function testRoundTrippedModelEvaluatesIdentically(): void
    {
        $model = Dmn::fromFile(self::FIXTURES . '/fees.dmn');
        $cached = $this->roundTrip($model);
        $inputs = ['applicant' => ['age' => 34, 'income' => 51_000]];

        $original = $model->evaluateDecision('Discounted Fee', $inputs);
        $replayed = $cached->evaluateDecision('Discounted Fee', $inputs);

        self::assertFalse($replayed->hasErrors());
        self::assertEquals($original->value(), $replayed->value());
        self::assertEquals($original->matchedRules(), $replayed->matchedRules());
        self::assertEquals($original->diagnostics(), $replayed->diagnostics());
    }

    public function testKnowledgeModelsAndServicesSurviveTheRoundTrip(): void
    {
        $model = Dmn::fromString(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <itemDefinition id="tAmount" name="tAmount"><typeRef>number</typeRef></itemDefinition>
              <inputData id="baseInput" name="base"><variable name="base" typeRef="tAmount"/></inputData>
              <decision id="priceDecision" name="Price">
                <variable name="Price" typeRef="number"/>
                <informationRequirement><requiredInput href="#baseInput"/></informationRequirement>
                <knowledgeRequirement><requiredKnowledge href="#taxBkm"/></knowledgeRequirement>
                <literalExpression><text>apply tax(base, 0.19)</text></literalExpression>
              </decision>
              <businessKnowledgeModel id="taxBkm" name="apply tax">
                <variable name="apply tax"/>
                <encapsulatedLogic>
                  <formalParameter name="amount" typeRef="number"/>
                  <formalParameter name="rate" typeRef="number"/>
                  <literalExpression><text>amount * (1 + rate)</text></literalExpression>
                </encapsulatedLogic>
              </businessKnowledgeModel>
              <decisionService id="pricingService" name="Pricing Service">
                <outputDecision href="#priceDecision"/>
                <inputData href="#baseInput"/>
              </decisionService>
            </definitions>
            XML);
        $cached = $this->roundTrip($model);

        self::assertEquals($model->invocables(), $cached->invocables());

        $original = $model->evaluateService('Pricing Service', ['base' => 100]);
        $replayed = $cached->evaluateService('Pricing Service', ['base' => 100]);

        self::assertFalse($replayed->hasErrors());
        self::assertEquals($original->value(), $replayed->value());

        $value = $replayed->value();
        self::assertInstanceOf(BigDecimal::class, $value);
        self::assertSame('119.00', (string) $value);
    }

    public function testRoundTripAndInjectedClockCompose(): void
    {
        $model = Dmn::fromString(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <decision id="d" name="Today">
                <literalExpression><text>string(today())</text></literalExpression>
              </decision>
            </definitions>
            XML);
        $now = new \DateTimeImmutable('2026-07-17T10:00:00', new \DateTimeZone('UTC'));

        self::assertSame(
            $model->evaluateDecision('Today', now: $now)->value(),
            $this->roundTrip($model)->evaluateDecision('Today', now: $now)->value(),
        );
    }

    private function roundTrip(DmnModel $model): DmnModel
    {
        $cached = unserialize(serialize($model));

        self::assertInstanceOf(DmnModel::class, $cached);

        return $cached;
    }
}
