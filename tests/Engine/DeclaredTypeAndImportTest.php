<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Engine;

use Brick\Math\BigDecimal;
use OpenWerk\DecisionModelAndNotation\Dmn;
use OpenWerk\DecisionModelAndNotation\Parser\DirectoryImportResolver;
use PHPUnit\Framework\TestCase;

final class DeclaredTypeAndImportTest extends TestCase
{
    /**
     * TCK 1161-boxed-list-expression case 002: null elements conform to a
     * typed list in declared-type checks (while `instance of` stays strict —
     * see EvaluatorTest).
     */
    public function testNullElementsConformToTypedListsInDeclaredTypeChecks(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <itemDefinition id="stringList" name="stringList" isCollection="true">
                <typeRef>string</typeRef>
              </itemDefinition>
              <inputData id="aInput" name="A"><variable name="A" typeRef="string"/></inputData>
              <decision id="bList" name="BList">
                <variable name="BList" typeRef="stringList"/>
                <informationRequirement><requiredInput href="#aInput"/></informationRequirement>
                <list id="theList" typeRef="stringList">
                  <literalExpression typeRef="string"><text>string(A)</text></literalExpression>
                  <literalExpression typeRef="string"><text>string(A)</text></literalExpression>
                </list>
              </decision>
            </definitions>
            XML;

        $result = Dmn::fromString($xml)->evaluateDecision('BList', ['A' => null]);

        self::assertFalse($result->hasErrors());
        self::assertSame([null, null], $result->value());
    }

    /**
     * Matched rules of imported decisions surface in the caller's audit
     * trail, qualified by the import name.
     */
    public function testImportedMatchedRulesAreQualifiedByImportName(): void
    {
        $model = Dmn::fromFile(__DIR__ . '/../fixtures/imports/main.dmn');

        self::assertSame([], $model->validationMessages());

        $result = $model->evaluateDecision(
            'Doubled Level',
            [],
            ['urn:import-base' => ['points' => 7]],
        );

        self::assertFalse($result->hasErrors());

        $value = $result->value();
        self::assertInstanceOf(BigDecimal::class, $value);
        self::assertSame('4', (string) $value);

        $matched = array_map(
            static fn($rule): string => sprintf('%s#%d', $rule->decision, $rule->ruleIndex),
            $result->matchedRules(),
        );

        self::assertSame(['lib.Base Level#2'], $matched);
        self::assertSame(
            ['lib.Base Level'],
            array_keys($result->matchedRulesByDecision()),
        );
        self::assertSame('baseTable', $result->matchedRulesFor('lib.Base Level')[0]->tableId ?? null);
    }

    public function testExplicitResolverWorksForStrings(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../fixtures/imports/main.dmn');
        $model = Dmn::fromString($xml, new DirectoryImportResolver(__DIR__ . '/../fixtures/imports'));

        $result = $model->evaluateDecision('Doubled Level', [], ['urn:import-base' => ['points' => 2]]);

        self::assertFalse($result->hasErrors());
        self::assertSame([], $result->matchedRulesFor('Doubled Level'));
        self::assertCount(1, $result->matchedRulesFor('lib.Base Level'));
    }
}
