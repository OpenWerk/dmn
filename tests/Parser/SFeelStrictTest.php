<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Parser;

use OpenWerk\DecisionModelAndNotation\Dmn;
use OpenWerk\DecisionModelAndNotation\DmnModel;
use OpenWerk\DecisionModelAndNotation\Tests\Support\ValueAssertions;
use PHPUnit\Framework\TestCase;

/**
 * S-FEEL strict mode: a model (or a single expression) declaring an S-FEEL
 * expression language gets full-FEEL constructs rejected as position-aware
 * validation errors. Evaluation itself stays permissive — the messages are
 * the contract.
 */
final class SFeelStrictTest extends TestCase
{
    use ValueAssertions;

    private const string SFEEL = 'https://www.omg.org/spec/DMN/20230324/S-FEEL/';
    private const string FEEL = 'https://www.omg.org/spec/DMN/20230324/FEEL/';

    public function testSimpleExpressionsPassStrictMode(): void
    {
        foreach (['a * 2 + 1', 'date("2027-01-01") + duration("P1D")', '-(a ** 2)', 'a >= 3'] as $expression) {
            self::assertSame([], $this->sfeelMessages($this->model(self::SFEEL, $expression)), $expression);
        }
    }

    public function testSimpleUnaryTestsPassStrictMode(): void
    {
        foreach (['-', '< 10', '[1..10]', '1,2,3', 'not("a","b")', '"low"'] as $entry) {
            self::assertSame([], $this->sfeelMessages($this->model(self::SFEEL, 'a + 1', $entry)), $entry);
        }
    }

    public function testFullFeelConstructsAreRejected(): void
    {
        $expected = [
            'if a > 1 then 1 else 2' => 'if expressions',
            'for i in [1,2] return i' => 'for expressions',
            'some x in [1] satisfies x > 0' => 'some/every expressions',
            'a > 1 and a < 5' => "'and' expressions",
            'a between 1 and 5' => "'between' expressions",
            '[1,2,3]' => 'list literals',
            '{x: 1}' => 'context literals',
            'sum(1, 2)' => 'function invocations (sum)',
            'a instance of number' => "'instance of' expressions",
            'null' => "the 'null' literal",
        ];

        foreach ($expected as $expression => $construct) {
            $messages = $this->sfeelMessages($this->model(self::SFEEL, $expression));

            self::assertNotEmpty($messages, $expression);
            self::assertStringContainsString($construct, $messages[0], $expression);
        }
    }

    public function testTableEntriesAreCheckedToo(): void
    {
        $messages = $this->sfeelMessages($this->model(self::SFEEL, 'a + 1', 'count(a)'));

        self::assertCount(1, $messages);
        self::assertStringContainsString('function invocations (count)', $messages[0]);
    }

    public function testFeelModelsAreUnaffected(): void
    {
        self::assertSame([], $this->sfeelMessages($this->model(self::FEEL, 'if a > 1 then 1 else 2')));
    }

    public function testPerExpressionLanguageOverridesTheDefault(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <decision id="d" name="D">
                <literalExpression expressionLanguage="s-feel"><text>if true then 1 else 2</text></literalExpression>
              </decision>
            </definitions>
            XML;

        $messages = $this->sfeelMessages(Dmn::fromString($xml));

        self::assertCount(1, $messages);
        self::assertStringContainsString('if expressions', $messages[0]);
    }

    public function testStrictModeDoesNotBlockEvaluation(): void
    {
        $model = $this->model(self::SFEEL, 'if a > 1 then 1 else 2');

        self::assertTrue($model->hasValidationErrors());
        self::assertSame('1', self::str($model->evaluateDecision('D', ['a' => 5])->feelValue()));
    }

    /**
     * @return list<string>
     */
    private function sfeelMessages(DmnModel $model): array
    {
        $messages = [];

        foreach ($model->validationMessages() as $message) {
            if (str_contains((string) $message, 'S-FEEL')) {
                $messages[] = (string) $message;
            }
        }

        return $messages;
    }

    private function model(string $language, string $expression, string $entry = '-'): DmnModel
    {
        $xml = str_replace(
            ['__L__', '__E__', '__T__'],
            [$language, htmlspecialchars($expression, ENT_XML1), htmlspecialchars($entry, ENT_XML1)],
            <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/"
                             id="t" name="T" namespace="urn:t" expressionLanguage="__L__">
                  <inputData id="aIn" name="a"><variable name="a" typeRef="number"/></inputData>
                  <decision id="d" name="D">
                    <informationRequirement><requiredInput href="#aIn"/></informationRequirement>
                    <literalExpression><text>__E__</text></literalExpression>
                  </decision>
                  <decision id="t2" name="Table">
                    <informationRequirement><requiredInput href="#aIn"/></informationRequirement>
                    <decisionTable id="tbl" hitPolicy="FIRST">
                      <input id="i1"><inputExpression typeRef="number"><text>a</text></inputExpression></input>
                      <output id="o1" name="r" typeRef="string"/>
                      <rule id="r1">
                        <inputEntry id="e1"><text>__T__</text></inputEntry>
                        <outputEntry id="oe1"><text>"x"</text></outputEntry>
                      </rule>
                    </decisionTable>
                  </decision>
                </definitions>
                XML,
        );

        return Dmn::fromString($xml);
    }
}
