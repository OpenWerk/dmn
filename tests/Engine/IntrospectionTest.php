<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Engine;

use OpenWerk\DecisionModelAndNotation\Dmn;
use OpenWerk\DecisionModelAndNotation\DmnModel;
use OpenWerk\DecisionModelAndNotation\Invocable;
use OpenWerk\DecisionModelAndNotation\InvocableKind;
use OpenWerk\DecisionModelAndNotation\InvocableParameter;
use PHPUnit\Framework\TestCase;

final class IntrospectionTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../fixtures';

    public function testEnumeratesDecisionsWithTransitiveInputParameters(): void
    {
        $model = Dmn::fromFile(self::FIXTURES . '/fees.dmn');

        $invocables = $model->invocables();

        self::assertSame(
            ['Risk Category', 'Fee', 'Discounted Fee'],
            array_map(static fn(Invocable $invocable): string => $invocable->name, $invocables),
        );
        self::assertSame(
            [InvocableKind::Decision, InvocableKind::Decision, InvocableKind::Decision],
            array_map(static fn(Invocable $invocable): InvocableKind => $invocable->kind, $invocables),
        );

        [$riskCategory, $fee, $discountedFee] = $invocables;

        // Direct requirement: Risk Category reads the applicant input.
        self::assertSame('riskCategory', $riskCategory->id);
        self::assertSame(['applicant'], $riskCategory->parameterNames());
        self::assertSame('tApplicant', $riskCategory->parameters[0]->typeRef);
        self::assertSame('string', $riskCategory->resultTypeRef);

        // Transitive requirements: Fee and Discounted Fee only require other
        // decisions, but callers still have to supply the applicant.
        self::assertSame(['applicant'], $fee->parameterNames());
        self::assertSame(['applicant'], $discountedFee->parameterNames());
        self::assertSame('number', $discountedFee->resultTypeRef);
    }

    public function testEnumeratesKnowledgeModelsAndServices(): void
    {
        $model = $this->serviceModel();

        $byName = [];

        foreach ($model->invocables() as $invocable) {
            $byName[$invocable->name] = $invocable;
        }

        $bkm = $byName['apply tax'];
        self::assertSame(InvocableKind::BusinessKnowledgeModel, $bkm->kind);
        self::assertSame(['amount', 'rate'], $bkm->parameterNames());
        self::assertSame('number', $bkm->parameters[0]->typeRef);
        self::assertNull($bkm->parameters[1]->typeRef);
        self::assertSame('number', $bkm->resultTypeRef);

        // Service parameters are the input data followed by the input
        // decisions, in declaration order — the FEEL invocation signature.
        $service = $byName['Pricing Service'];
        self::assertSame(InvocableKind::DecisionService, $service->kind);
        self::assertSame('pricingService', $service->id);
        self::assertSame(['base', 'Margin'], $service->parameterNames());
        self::assertSame(['number', 'number'], array_map(
            static fn(InvocableParameter $parameter): ?string => $parameter->typeRef,
            $service->parameters,
        ));
        self::assertSame('number', $service->resultTypeRef);
    }

    public function testDerivesImplicitParametersOfTableBackedKnowledgeModels(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <businessKnowledgeModel id="gradeBkm" name="grade">
                <variable name="grade"/>
                <encapsulatedLogic>
                  <decisionTable id="gradeTable" hitPolicy="UNIQUE">
                    <input id="i1"><inputExpression typeRef="number"><text>score.points</text></inputExpression></input>
                    <input id="i2"><inputExpression typeRef="number"><text>score.points</text></inputExpression></input>
                    <output id="o1" name="grade" typeRef="string"/>
                    <rule>
                      <inputEntry><text>&lt; 50</text></inputEntry>
                      <inputEntry><text>-</text></inputEntry>
                      <outputEntry><text>"fail"</text></outputEntry>
                    </rule>
                    <rule>
                      <inputEntry><text>&gt;= 50</text></inputEntry>
                      <inputEntry><text>-</text></inputEntry>
                      <outputEntry><text>"pass"</text></outputEntry>
                    </rule>
                  </decisionTable>
                </encapsulatedLogic>
              </businessKnowledgeModel>
            </definitions>
            XML;

        $invocables = Dmn::fromString($xml)->invocables();

        self::assertCount(1, $invocables);
        // Distinct root names of the input expressions, deduplicated.
        self::assertSame(['score'], $invocables[0]->parameterNames());
        self::assertSame('string', $invocables[0]->resultTypeRef);
    }

    public function testGroupsMatchedRulesByDecision(): void
    {
        $model = Dmn::fromFile(self::FIXTURES . '/fees.dmn');

        $result = $model->evaluateDecision('Discounted Fee', [
            'applicant' => ['age' => 34, 'income' => 51_000],
        ]);

        $grouped = $result->matchedRulesByDecision();

        self::assertSame(['Risk Category', 'Fee'], array_keys($grouped));
        self::assertCount(1, $grouped['Risk Category']);
        self::assertSame(3, $grouped['Risk Category'][0]->ruleIndex);

        $feeRules = $result->matchedRulesFor('Fee');
        self::assertCount(1, $feeRules);
        self::assertSame('feeRule1', $feeRules[0]->ruleId);
        self::assertSame('feeTable', $feeRules[0]->tableId);

        // The literal-expression decision records no table hits.
        self::assertSame([], $result->matchedRulesFor('Discounted Fee'));
        self::assertSame([], $result->matchedRulesFor('Nope'));
    }

    public function testGroupingKeepsEveryHitOfMultiHitPolicies(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <inputData id="scoreInput" name="score"><variable name="score" typeRef="number"/></inputData>
              <decision id="bonuses" name="Bonuses">
                <informationRequirement><requiredInput href="#scoreInput"/></informationRequirement>
                <decisionTable id="bonusTable" hitPolicy="COLLECT">
                  <input id="i1"><inputExpression typeRef="number"><text>score</text></inputExpression></input>
                  <output id="o1" name="bonus" typeRef="number"/>
                  <rule id="r1">
                    <inputEntry><text>&gt; 0</text></inputEntry>
                    <outputEntry><text>1</text></outputEntry>
                  </rule>
                  <rule id="r2">
                    <inputEntry><text>&gt; 10</text></inputEntry>
                    <outputEntry><text>2</text></outputEntry>
                  </rule>
                  <rule id="r3">
                    <inputEntry><text>&gt; 99</text></inputEntry>
                    <outputEntry><text>3</text></outputEntry>
                  </rule>
                </decisionTable>
              </decision>
            </definitions>
            XML;

        $result = Dmn::fromString($xml)->evaluateDecision('Bonuses', ['score' => 42]);

        $rules = $result->matchedRulesFor('Bonuses');

        self::assertSame([1, 2], array_map(static fn($rule): int => $rule->ruleIndex, $rules));
        self::assertSame(['bonusTable', 'bonusTable'], array_map(static fn($rule): ?string => $rule->tableId, $rules));
    }

    private function serviceModel(): DmnModel
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <inputData id="baseInput" name="base"><variable name="base" typeRef="number"/></inputData>
              <decision id="marginDecision" name="Margin">
                <variable name="Margin" typeRef="number"/>
                <literalExpression><text>1.2</text></literalExpression>
              </decision>
              <decision id="priceDecision" name="Price">
                <variable name="Price" typeRef="number"/>
                <informationRequirement><requiredInput href="#baseInput"/></informationRequirement>
                <informationRequirement><requiredDecision href="#marginDecision"/></informationRequirement>
                <knowledgeRequirement><requiredKnowledge href="#taxBkm"/></knowledgeRequirement>
                <literalExpression><text>apply tax(base * Margin, 0.19)</text></literalExpression>
              </decision>
              <businessKnowledgeModel id="taxBkm" name="apply tax">
                <variable name="apply tax"/>
                <encapsulatedLogic>
                  <formalParameter name="amount" typeRef="number"/>
                  <formalParameter name="rate"/>
                  <literalExpression typeRef="number"><text>amount * (1 + rate)</text></literalExpression>
                </encapsulatedLogic>
              </businessKnowledgeModel>
              <decisionService id="pricingService" name="Pricing Service">
                <variable name="Pricing Service" typeRef="number"/>
                <outputDecision href="#priceDecision"/>
                <inputDecision href="#marginDecision"/>
                <inputData href="#baseInput"/>
              </decisionService>
            </definitions>
            XML;

        return Dmn::fromString($xml);
    }
}
