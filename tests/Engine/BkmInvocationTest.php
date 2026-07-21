<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Engine;

use OpenWerk\DecisionModelAndNotation\Dmn;
use OpenWerk\DecisionModelAndNotation\DmnModel;
use OpenWerk\DecisionModelAndNotation\Tests\Support\ValueAssertions;
use PHPUnit\Framework\TestCase;

final class BkmInvocationTest extends TestCase
{
    use ValueAssertions;

    private function model(): DmnModel
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <inputData id="baseInput" name="base"><variable name="base" typeRef="number"/></inputData>
              <decision id="decision" name="Total">
                <variable name="Total" typeRef="number"/>
                <informationRequirement><requiredInput href="#baseInput"/></informationRequirement>
                <knowledgeRequirement><requiredKnowledge href="#taxBkm"/></knowledgeRequirement>
                <literalExpression><text>apply tax(base, 0.19) + 1</text></literalExpression>
              </decision>
              <businessKnowledgeModel id="taxBkm" name="apply tax">
                <variable name="apply tax"/>
                <encapsulatedLogic>
                  <formalParameter name="amount" typeRef="number"/>
                  <formalParameter name="rate" typeRef="number"/>
                  <literalExpression><text>amount * (1 + rate)</text></literalExpression>
                </encapsulatedLogic>
              </businessKnowledgeModel>
            </definitions>
            XML;

        return Dmn::fromString($xml);
    }

    public function testInvokesBkmFromLiteralExpression(): void
    {
        $model = $this->model();

        self::assertSame([], $model->validationMessages());

        $result = $model->evaluateDecision('Total', ['base' => 100]);

        self::assertFalse($result->hasErrors());
        self::assertSame('120', self::str($result->feelValue()));
    }

    public function testBkmScopeIsIsolatedFromCallerScope(): void
    {
        // The BKM body must only see its formal parameters — not the
        // decision's inputs.
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <inputData id="secretInput" name="secret"><variable name="secret" typeRef="number"/></inputData>
              <decision id="decision" name="Leak">
                <informationRequirement><requiredInput href="#secretInput"/></informationRequirement>
                <knowledgeRequirement><requiredKnowledge href="#leakBkm"/></knowledgeRequirement>
                <literalExpression><text>reveal(1)</text></literalExpression>
              </decision>
              <businessKnowledgeModel id="leakBkm" name="reveal">
                <encapsulatedLogic>
                  <formalParameter name="x" typeRef="number"/>
                  <literalExpression><text>secret + x</text></literalExpression>
                </encapsulatedLogic>
              </businessKnowledgeModel>
            </definitions>
            XML;

        $result = Dmn::fromString($xml)->evaluateDecision('Leak', ['secret' => 42]);

        self::assertNull($result->feelValue());
        self::assertTrue($result->hasErrors());
    }

    public function testWrongArgumentCountYieldsNullWithDiagnostic(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <decision id="decision" name="Broken">
                <knowledgeRequirement><requiredKnowledge href="#twiceBkm"/></knowledgeRequirement>
                <literalExpression><text>twice(1, 2)</text></literalExpression>
              </decision>
              <businessKnowledgeModel id="twiceBkm" name="twice">
                <encapsulatedLogic>
                  <formalParameter name="x" typeRef="number"/>
                  <literalExpression><text>x * 2</text></literalExpression>
                </encapsulatedLogic>
              </businessKnowledgeModel>
            </definitions>
            XML;

        $result = Dmn::fromString($xml)->evaluateDecision('Broken');

        self::assertNull($result->feelValue());
        self::assertTrue($result->hasErrors());
        self::assertStringContainsString('expects 1 arguments, 2 given', $result->diagnostics()[0]->message);
    }

    public function testBkmWithoutLogicYieldsWarningAndNull(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <decision id="decision" name="NoLogic">
                <knowledgeRequirement><requiredKnowledge href="#emptyBkm"/></knowledgeRequirement>
                <literalExpression><text>helper(1)</text></literalExpression>
              </decision>
              <businessKnowledgeModel id="emptyBkm" name="helper"/>
            </definitions>
            XML;

        $result = Dmn::fromString($xml)->evaluateDecision('NoLogic');

        self::assertNull($result->feelValue());
        self::assertNotEmpty($result->diagnostics());
    }
}
