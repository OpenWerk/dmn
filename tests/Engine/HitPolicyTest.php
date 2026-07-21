<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Engine;

use Brick\Math\BigDecimal;
use OpenWerk\DecisionModelAndNotation\Diagnostics\Diagnostic;
use OpenWerk\DecisionModelAndNotation\Dmn;
use OpenWerk\DecisionModelAndNotation\DmnModel;
use OpenWerk\DecisionModelAndNotation\Engine\EvaluationResult;
use OpenWerk\DecisionModelAndNotation\Tests\Support\ValueAssertions;
use PHPUnit\Framework\TestCase;

/**
 * The hit policy matrix, exercised through single-decision models built
 * from inline DMN XML.
 */
final class HitPolicyTest extends TestCase
{
    use ValueAssertions;

    /**
     * Builds a one-input decision table over the input data `score`.
     *
     * @param list<array{string, string}> $rules [input entry, output entry] pairs
     */
    private function table(
        string $hitPolicy,
        array $rules,
        string $aggregation = '',
        string $outputValues = '',
        string $defaultOutput = '',
    ): DmnModel {
        $aggregationAttribute = $aggregation === '' ? '' : sprintf(' aggregation="%s"', $aggregation);
        $outputValuesXml = $outputValues === ''
            ? ''
            : sprintf('<outputValues><text>%s</text></outputValues>', htmlspecialchars($outputValues, ENT_XML1));
        $defaultXml = $defaultOutput === ''
            ? ''
            : sprintf(
                '<defaultOutputEntry><text>%s</text></defaultOutputEntry>',
                htmlspecialchars($defaultOutput, ENT_XML1),
            );

        $rulesXml = '';

        foreach ($rules as $index => [$inputEntry, $outputEntry]) {
            $rulesXml .= sprintf(
                '<rule id="rule%d"><inputEntry><text>%s</text></inputEntry>'
                . '<outputEntry><text>%s</text></outputEntry></rule>',
                $index + 1,
                htmlspecialchars($inputEntry, ENT_XML1),
                htmlspecialchars($outputEntry, ENT_XML1),
            );
        }

        $xml = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <inputData id="scoreInput" name="score">
                <variable name="score" typeRef="number"/>
              </inputData>
              <decision id="decision" name="Decision">
                <variable name="Decision"/>
                <informationRequirement><requiredInput href="#scoreInput"/></informationRequirement>
                <decisionTable id="table" hitPolicy="{$hitPolicy}"{$aggregationAttribute}>
                  <input id="input"><inputExpression typeRef="number"><text>score</text></inputExpression></input>
                  <output id="output" name="result">{$outputValuesXml}{$defaultXml}</output>
                  {$rulesXml}
                </decisionTable>
              </decision>
            </definitions>
            XML;

        return Dmn::fromString($xml);
    }

    private function decide(DmnModel $model, int $score): EvaluationResult
    {
        return $model->evaluateDecision('Decision', ['score' => $score]);
    }

    /**
     * @param list<string> $expected
     */
    private static function assertNumberList(array $expected, mixed $actual): void
    {
        $strings = [];

        foreach (self::arr($actual) as $item) {
            $strings[] = self::str($item);
        }

        self::assertSame($expected, $strings);
    }

    public function testUniqueSingleMatch(): void
    {
        $model = $this->table('UNIQUE', [['< 10', '"low"'], ['>= 10', '"high"']]);
        $result = $this->decide($model, 5);

        self::assertSame('low', $result->feelValue());
        self::assertCount(1, $result->matchedRules());
        self::assertSame(1, $result->matchedRules()[0]->ruleIndex);
        self::assertSame('rule1', $result->matchedRules()[0]->ruleId);
    }

    public function testUniqueViolationYieldsNullAndError(): void
    {
        $model = $this->table('UNIQUE', [['< 10', '"a"'], ['< 20', '"b"']]);
        $result = $this->decide($model, 5);

        self::assertNull($result->feelValue());
        self::assertTrue($result->hasErrors());
        self::assertSame(Diagnostic::TABLE_UNIQUE_VIOLATION, $result->diagnostics()[0]->code);
        self::assertCount(2, $result->matchedRules());
    }

    public function testAnyWithEqualOutputs(): void
    {
        $model = $this->table('ANY', [['< 10', '"same"'], ['< 20', '"same"']]);

        self::assertSame('same', $this->decide($model, 5)->feelValue());
    }

    public function testAnyViolationWithDifferentOutputs(): void
    {
        $model = $this->table('ANY', [['< 10', '"a"'], ['< 20', '"b"']]);
        $result = $this->decide($model, 5);

        self::assertNull($result->feelValue());
        self::assertSame(Diagnostic::TABLE_ANY_VIOLATION, $result->diagnostics()[0]->code);
    }

    public function testFirstTakesRuleOrder(): void
    {
        $model = $this->table('FIRST', [['< 20', '"first"'], ['< 10', '"second"']]);

        self::assertSame('first', $this->decide($model, 5)->feelValue());
    }

    public function testPriorityPicksHighestOutputValue(): void
    {
        $model = $this->table(
            'PRIORITY',
            [['< 20', '"approve"'], ['< 10', '"decline"']],
            outputValues: '"decline","refer","approve"',
        );

        // Both rules match; "decline" is listed first = highest priority.
        self::assertSame('decline', $this->decide($model, 5)->feelValue());
    }

    public function testPriorityWithoutOutputValuesFallsBackToRuleOrder(): void
    {
        $model = $this->table('PRIORITY', [['< 20', '"a"'], ['< 10', '"b"']]);
        $result = $this->decide($model, 5);

        self::assertSame('a', $result->feelValue());
        self::assertFalse($result->hasErrors());
        self::assertContains(
            Diagnostic::TABLE_PRIORITY_UNDEFINED,
            array_map(static fn(Diagnostic $d): string => $d->code, $result->diagnostics()),
        );
    }

    public function testOutputOrderWithPartialOutputValuesOrdersByDefinedOutputs(): void
    {
        // Only the first output defines output values; the second must not
        // participate in the ordering (and must not cause an error).
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <inputData id="scoreInput" name="score"><variable name="score" typeRef="number"/></inputData>
              <decision id="decision" name="Decision">
                <informationRequirement><requiredInput href="#scoreInput"/></informationRequirement>
                <decisionTable id="table" hitPolicy="OUTPUT ORDER">
                  <input id="input"><inputExpression typeRef="number"><text>score</text></inputExpression></input>
                  <output id="out1" name="grade">
                    <outputValues><text>"gold","silver"</text></outputValues>
                  </output>
                  <output id="out2" name="note"/>
                  <rule id="rule1">
                    <inputEntry><text>-</text></inputEntry>
                    <outputEntry><text>"silver"</text></outputEntry>
                    <outputEntry><text>"second"</text></outputEntry>
                  </rule>
                  <rule id="rule2">
                    <inputEntry><text>&lt; 10</text></inputEntry>
                    <outputEntry><text>"gold"</text></outputEntry>
                    <outputEntry><text>"first"</text></outputEntry>
                  </rule>
                </decisionTable>
              </decision>
            </definitions>
            XML;

        $result = Dmn::fromString($xml)->evaluateDecision('Decision', ['score' => 5]);

        self::assertFalse($result->hasErrors());
        self::assertSame(
            [
                ['grade' => 'gold', 'note' => 'first'],
                ['grade' => 'silver', 'note' => 'second'],
            ],
            $result->feelValue(),
        );
    }

    public function testOutputOrderSortsByPriority(): void
    {
        $model = $this->table(
            'OUTPUT ORDER',
            [['< 20', '"refer"'], ['< 10', '"decline"'], ['-', '"approve"']],
            outputValues: '"decline","refer","approve"',
        );

        $result = $this->decide($model, 5);

        self::assertSame(['decline', 'refer', 'approve'], $result->feelValue());
    }

    public function testRuleOrderKeepsRuleSequence(): void
    {
        $model = $this->table('RULE ORDER', [['< 20', '"a"'], ['< 10', '"b"'], ['> 90', '"c"']]);

        self::assertSame(['a', 'b'], $this->decide($model, 5)->feelValue());
    }

    public function testCollectWithoutAggregatorReturnsList(): void
    {
        $model = $this->table('COLLECT', [['< 20', '1'], ['< 10', '2'], ['> 90', '3']]);

        self::assertNumberList(['1', '2'], $this->decide($model, 5)->feelValue());
    }

    public function testCollectSum(): void
    {
        $model = $this->table('COLLECT', [['-', '5'], ['< 10', '3'], ['< 3', '1']], aggregation: 'SUM');
        $value = $this->decide($model, 5)->value();

        self::assertInstanceOf(BigDecimal::class, $value);
        self::assertSame('8', self::str($value));
    }

    public function testCollectMinMaxCount(): void
    {
        $rules = [['-', '5'], ['< 10', '3'], ['-', '9']];

        $aggregate = fn(string $aggregation): string => self::str(
            $this->decide($this->table('COLLECT', $rules, aggregation: $aggregation), 5)->feelValue(),
        );

        self::assertSame('3', $aggregate('MIN'));
        self::assertSame('9', $aggregate('MAX'));
        self::assertSame('3', $aggregate('COUNT'));
    }

    public function testCollectSumOverNonNumbersIsAnError(): void
    {
        $model = $this->table('COLLECT', [['-', '"a"'], ['-', '"b"']], aggregation: 'SUM');
        $result = $this->decide($model, 5);

        self::assertNull($result->feelValue());
        self::assertSame(Diagnostic::TABLE_AGGREGATION_ERROR, $result->diagnostics()[0]->code);
    }

    public function testNoMatchWithoutDefaultIsNullWithInfo(): void
    {
        $model = $this->table('UNIQUE', [['> 100', '"x"']]);
        $result = $this->decide($model, 5);

        self::assertNull($result->feelValue());
        self::assertFalse($result->hasErrors());
        self::assertSame(Diagnostic::TABLE_NO_MATCH, $result->diagnostics()[0]->code);
        self::assertSame([], $result->matchedRules());
    }

    public function testNoMatchUsesDefaultOutput(): void
    {
        $model = $this->table('UNIQUE', [['> 100', '"x"']], defaultOutput: '"fallback"');
        $result = $this->decide($model, 5);

        self::assertSame('fallback', $result->feelValue());
        self::assertFalse($result->hasErrors());
    }

    public function testOutputValuesViolationYieldsNull(): void
    {
        $model = $this->table('UNIQUE', [['< 10', '"purple"']], outputValues: '"low","high"');
        $result = $this->decide($model, 5);

        self::assertNull($result->feelValue());
        self::assertSame(Diagnostic::TABLE_OUTPUT_VALUES_VIOLATION, $result->diagnostics()[0]->code);
    }

    public function testMultiOutputTableComposesContext(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <inputData id="scoreInput" name="score"><variable name="score" typeRef="number"/></inputData>
              <decision id="decision" name="Decision">
                <informationRequirement><requiredInput href="#scoreInput"/></informationRequirement>
                <decisionTable id="table" hitPolicy="UNIQUE">
                  <input id="input"><inputExpression typeRef="number"><text>score</text></inputExpression></input>
                  <output id="out1" name="grade"/>
                  <output id="out2" name="bonus"/>
                  <rule id="rule1">
                    <inputEntry><text>&lt; 10</text></inputEntry>
                    <outputEntry><text>"low"</text></outputEntry>
                    <outputEntry><text>score * 2</text></outputEntry>
                  </rule>
                </decisionTable>
              </decision>
            </definitions>
            XML;

        $result = Dmn::fromString($xml)->evaluateDecision('Decision', ['score' => 4]);
        $value = self::arr($result->value());

        self::assertSame('low', $value['grade']);
        self::assertInstanceOf(BigDecimal::class, $value['bonus']);
        self::assertSame('8', self::str($value['bonus']));
    }

    public function testInputValuesViolationYieldsNull(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <inputData id="scoreInput" name="score"><variable name="score" typeRef="number"/></inputData>
              <decision id="decision" name="Decision">
                <informationRequirement><requiredInput href="#scoreInput"/></informationRequirement>
                <decisionTable id="table" hitPolicy="UNIQUE">
                  <input id="input">
                    <inputExpression typeRef="number"><text>score</text></inputExpression>
                    <inputValues><text>[0..100]</text></inputValues>
                  </input>
                  <output id="out" name="result"/>
                  <rule id="rule1">
                    <inputEntry><text>-</text></inputEntry>
                    <outputEntry><text>"ok"</text></outputEntry>
                  </rule>
                </decisionTable>
              </decision>
            </definitions>
            XML;

        $model = Dmn::fromString($xml);

        self::assertSame('ok', $model->evaluateDecision('Decision', ['score' => 50])->feelValue());

        $violated = $model->evaluateDecision('Decision', ['score' => 200]);
        self::assertNull($violated->feelValue());
        self::assertSame(Diagnostic::TABLE_INPUT_VALUES_VIOLATION, $violated->diagnostics()[0]->code);
    }
}
