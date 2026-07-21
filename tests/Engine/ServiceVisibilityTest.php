<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Engine;

use OpenWerk\DecisionModelAndNotation\Diagnostics\Severity;
use OpenWerk\DecisionModelAndNotation\Dmn;
use OpenWerk\DecisionModelAndNotation\DmnModel;
use OpenWerk\DecisionModelAndNotation\Tests\Support\ValueAssertions;
use PHPUnit\Framework\TestCase;

/**
 * Name resolution inside a decision service is restricted to what the
 * service declares: its input data, input decisions, encapsulated and
 * output decisions. Requirements outside these sets error to null instead
 * of silently resolving against the whole model.
 */
final class ServiceVisibilityTest extends TestCase
{
    use ValueAssertions;

    public function testWellFormedServiceEvaluates(): void
    {
        $result = $this->model()->evaluateService('Proper Service', ['a' => 41]);

        self::assertFalse($result->hasErrors());
        self::assertSame('42', self::str($result->feelValue()));
    }

    public function testUndeclaredRequiredDecisionErrorsToNull(): void
    {
        $result = $this->model()->evaluateService('Leaky Service', ['a' => 1, 'b' => 2]);

        self::assertTrue($result->hasErrors());
        self::assertNull($result->value());

        $messages = array_map(
            static fn($diagnostic): string => (string) $diagnostic,
            $result->diagnostics(),
        );
        $visibility = array_filter(
            $messages,
            static fn(string $message): bool => str_contains($message, 'not visible inside decision service'),
        );

        self::assertNotEmpty($visibility);
    }

    public function testUndeclaredInputDataErrorsToNull(): void
    {
        $result = $this->model()->evaluateService('Underdeclared Service', ['a' => 1, 'b' => 2]);

        self::assertTrue($result->hasErrors());
        self::assertNull($result->value());
    }

    public function testTheSameDecisionStaysUnrestrictedOutsideTheService(): void
    {
        $result = $this->model()->evaluateDecision('Leaky', ['b' => 2]);

        self::assertFalse($result->hasErrors());
        self::assertSame('21', self::str($result->feelValue()));
    }

    public function testEncapsulatedDecisionsStayReachable(): void
    {
        $result = $this->model()->evaluateService('Encapsulating Service', ['b' => 2]);

        self::assertFalse($result->hasErrors());
        self::assertSame('21', self::str($result->feelValue()));
        self::assertSame(
            [],
            array_filter($result->diagnostics(), static fn($d): bool => $d->severity === Severity::Error),
        );
    }

    private function model(): DmnModel
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <inputData id="aIn" name="a"><variable name="a" typeRef="number"/></inputData>
              <inputData id="bIn" name="b"><variable name="b" typeRef="number"/></inputData>
              <decision id="hidden" name="Hidden">
                <variable name="Hidden" typeRef="number"/>
                <informationRequirement><requiredInput href="#bIn"/></informationRequirement>
                <literalExpression><text>b * 10</text></literalExpression>
              </decision>
              <decision id="leaky" name="Leaky">
                <variable name="Leaky" typeRef="number"/>
                <informationRequirement><requiredDecision href="#hidden"/></informationRequirement>
                <literalExpression><text>Hidden + 1</text></literalExpression>
              </decision>
              <decision id="needsB" name="Needs B">
                <variable name="Needs B" typeRef="number"/>
                <informationRequirement><requiredInput href="#bIn"/></informationRequirement>
                <literalExpression><text>b + 1</text></literalExpression>
              </decision>
              <decision id="proper" name="Proper">
                <variable name="Proper" typeRef="number"/>
                <informationRequirement><requiredInput href="#aIn"/></informationRequirement>
                <literalExpression><text>a + 1</text></literalExpression>
              </decision>
              <decisionService id="properSvc" name="Proper Service">
                <outputDecision href="#proper"/>
                <inputData href="#aIn"/>
              </decisionService>
              <decisionService id="leakySvc" name="Leaky Service">
                <outputDecision href="#leaky"/>
                <inputData href="#aIn"/>
              </decisionService>
              <decisionService id="underdeclaredSvc" name="Underdeclared Service">
                <outputDecision href="#needsB"/>
                <inputData href="#aIn"/>
              </decisionService>
              <decisionService id="encapsulatingSvc" name="Encapsulating Service">
                <outputDecision href="#leaky"/>
                <encapsulatedDecision href="#hidden"/>
                <inputData href="#bIn"/>
              </decisionService>
            </definitions>
            XML;

        return Dmn::fromString($xml);
    }
}
