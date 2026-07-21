<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Parser;

use OpenWerk\DecisionModelAndNotation\Diagnostics\Severity;
use OpenWerk\DecisionModelAndNotation\Dmn;
use OpenWerk\DecisionModelAndNotation\Exception\ParseException;
use OpenWerk\DecisionModelAndNotation\Model\DecisionTable;
use OpenWerk\DecisionModelAndNotation\Model\HitPolicy;
use OpenWerk\DecisionModelAndNotation\Tests\Support\ValueAssertions;
use OpenWerk\DecisionModelAndNotation\Validation\ValidationMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DmnParserTest extends TestCase
{
    use ValueAssertions;

    private const string FIXTURES = __DIR__ . '/../fixtures';

    /**
     * @param list<ValidationMessage> $messages
     *
     * @return list<string>
     */
    private static function messageTexts(array $messages): array
    {
        return array_map(static fn(ValidationMessage $message): string => (string) $message, $messages);
    }

    #[DataProvider('namespaces')]
    public function testAcceptsDmnNamespaces(string $namespaceUri, string $version): void
    {
        $xml = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="{$namespaceUri}" id="d" name="Test" namespace="urn:test">
              <decision id="dec" name="Answer">
                <literalExpression><text>42</text></literalExpression>
              </decision>
            </definitions>
            XML;

        $model = Dmn::fromString($xml);

        self::assertSame($version, $model->definitions()->dmnVersion);
        self::assertSame(['Answer'], $model->decisionNames());
        self::assertSame('42', self::str($model->evaluateDecision('Answer')->feelValue()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function namespaces(): iterable
    {
        yield 'DMN 1.2' => ['http://www.omg.org/spec/DMN/20180521/MODEL/', '1.2'];
        yield 'DMN 1.3' => ['https://www.omg.org/spec/DMN/20191111/MODEL/', '1.3'];
        yield 'DMN 1.4' => ['https://www.omg.org/spec/DMN/20211108/MODEL/', '1.4'];
        yield 'DMN 1.5' => ['https://www.omg.org/spec/DMN/20230324/MODEL/', '1.5'];
    }

    public function testRejectsNonDmnNamespace(): void
    {
        $this->expectException(ParseException::class);

        Dmn::fromString('<definitions xmlns="urn:not-dmn"/>');
    }

    public function testRejectsMalformedXml(): void
    {
        $this->expectException(ParseException::class);

        Dmn::fromString('<definitions><unclosed>');
    }

    public function testRejectsMissingFile(): void
    {
        $this->expectException(ParseException::class);

        Dmn::fromFile(self::FIXTURES . '/does-not-exist.dmn');
    }

    public function testParsesFullFixtureWithoutFindings(): void
    {
        $model = Dmn::fromFile(self::FIXTURES . '/fees.dmn');

        self::assertSame([], $model->validationMessages());
        self::assertFalse($model->hasValidationErrors());
        self::assertSame(['Risk Category', 'Fee', 'Discounted Fee'], $model->decisionNames());

        $definitions = $model->definitions();
        self::assertSame('1.5', $definitions->dmnVersion);
        self::assertCount(1, $definitions->inputData);
        self::assertCount(1, $definitions->itemDefinitions);
        self::assertCount(2, $definitions->itemDefinitions[0]->components);

        $risk = $definitions->decisionByName('Risk Category');
        self::assertNotNull($risk);
        self::assertSame(['applicantInput'], $risk->requiredInputs);

        $table = $risk->expression;
        self::assertInstanceOf(DecisionTable::class, $table);
        self::assertSame(HitPolicy::Unique, $table->hitPolicy);
        self::assertCount(2, $table->inputs);
        self::assertCount(4, $table->rules);
    }

    public function testDmndiContentIsIgnored(): void
    {
        // fees.dmn carries a dmndi:DMNDI section; parsing it produced no
        // messages and no phantom elements.
        $model = Dmn::fromFile(self::FIXTURES . '/fees.dmn');

        self::assertCount(3, $model->definitions()->decisions);
    }

    public function testReportsUnknownReferences(): void
    {
        $xml = <<<'XML'
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="d" name="T" namespace="urn:t">
              <decision id="a" name="A">
                <informationRequirement><requiredDecision href="#ghost"/></informationRequirement>
                <informationRequirement><requiredInput href="#missingInput"/></informationRequirement>
                <literalExpression><text>1</text></literalExpression>
              </decision>
            </definitions>
            XML;

        $model = Dmn::fromString($xml);

        self::assertTrue($model->hasValidationErrors());
        $texts = self::messageTexts($model->validationMessages());
        self::assertNotEmpty(preg_grep('/unknown decision .+ghost/', $texts));
        self::assertNotEmpty(preg_grep('/unknown input data .+missingInput/', $texts));
    }

    public function testReportsDuplicateNames(): void
    {
        $xml = <<<'XML'
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="d" name="T" namespace="urn:t">
              <decision id="a" name="Same"><literalExpression><text>1</text></literalExpression></decision>
              <decision id="b" name="Same"><literalExpression><text>2</text></literalExpression></decision>
            </definitions>
            XML;

        $model = Dmn::fromString($xml);

        self::assertTrue($model->hasValidationErrors());
        self::assertNotEmpty(preg_grep(
            '/duplicate name/',
            self::messageTexts($model->validationMessages()),
        ));
    }

    public function testReportsRequirementCycles(): void
    {
        $xml = <<<'XML'
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="d" name="T" namespace="urn:t">
              <decision id="a" name="A">
                <informationRequirement><requiredDecision href="#b"/></informationRequirement>
                <literalExpression><text>B</text></literalExpression>
              </decision>
              <decision id="b" name="B">
                <informationRequirement><requiredDecision href="#a"/></informationRequirement>
                <literalExpression><text>A</text></literalExpression>
              </decision>
            </definitions>
            XML;

        $model = Dmn::fromString($xml);

        self::assertTrue($model->hasValidationErrors());
        $cycleMessages = preg_grep(
            '/cyclic information requirement/',
            self::messageTexts($model->validationMessages()),
        );
        self::assertNotEmpty($cycleMessages);

        // Evaluation is guarded: it yields null with a cycle diagnostic
        // instead of recursing forever.
        $result = $model->evaluateDecision('A');
        self::assertNull($result->feelValue());
        self::assertTrue($result->hasErrors());
    }

    public function testReportsFeelSyntaxErrorsWithPosition(): void
    {
        $xml = <<<'XML'
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="d" name="T" namespace="urn:t">
              <decision id="a" name="A">
                <literalExpression id="brokenExpression"><text>1 +</text></literalExpression>
              </decision>
            </definitions>
            XML;

        $model = Dmn::fromString($xml);

        self::assertTrue($model->hasValidationErrors());
        $message = $model->validationMessages()[0];
        self::assertSame(Severity::Error, $message->severity);
        self::assertSame('brokenExpression', $message->elementId);
        self::assertNotNull($message->line);
        self::assertGreaterThan(1, $message->line);

        // The decision still evaluates -- to null, with a diagnostic.
        $result = $model->evaluateDecision('A');
        self::assertNull($result->feelValue());
        self::assertTrue($result->hasErrors());
    }

    public function testIncompleteBoxedExpressionsAreReportedNotFatal(): void
    {
        $xml = <<<'XML'
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="d" name="T" namespace="urn:t">
              <decision id="a" name="A">
                <conditional id="cond"></conditional>
              </decision>
            </definitions>
            XML;

        $model = Dmn::fromString($xml);

        self::assertFalse($model->hasValidationErrors());

        // A conditional without if/then/else children still evaluates -- to null.
        $result = $model->evaluateDecision('A');
        self::assertNull($result->feelValue());
    }

    public function testBoxedConditionalEvaluates(): void
    {
        $xml = <<<'XML'
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="d" name="T" namespace="urn:t">
              <inputData id="i" name="n"><variable name="n" typeRef="number"/></inputData>
              <decision id="a" name="A">
                <informationRequirement id="r"><requiredInput href="#i"/></informationRequirement>
                <conditional id="cond">
                  <if><literalExpression><text>n &gt; 10</text></literalExpression></if>
                  <then><literalExpression><text>"big"</text></literalExpression></then>
                  <else><literalExpression><text>"small"</text></literalExpression></else>
                </conditional>
              </decision>
            </definitions>
            XML;

        $model = Dmn::fromString($xml);

        self::assertFalse($model->hasValidationErrors());
        self::assertSame('big', $model->evaluateDecision('A', ['n' => 42])->value());
        self::assertSame('small', $model->evaluateDecision('A', ['n' => 3])->value());
    }

    public function testMetadataElementsAreParsed(): void
    {
        $xml = <<<'XML'
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="d" name="T" namespace="urn:t">
              <import namespace="urn:other" name="other" importType="https://www.omg.org/spec/DMN/20230324/MODEL/"/>
              <businessKnowledgeModel id="bkm" name="Scoring"/>
              <knowledgeSource id="ks" name="Policy Handbook"/>
              <decisionService id="svc" name="Service">
                <outputDecision href="#a"/>
              </decisionService>
              <decision id="a" name="A"><literalExpression><text>1</text></literalExpression></decision>
            </definitions>
            XML;

        $model = Dmn::fromString($xml);
        $definitions = $model->definitions();

        self::assertCount(1, $definitions->imports);
        self::assertCount(1, $definitions->businessKnowledgeModels);
        self::assertCount(1, $definitions->knowledgeSources);
        self::assertCount(1, $definitions->decisionServices);
        self::assertSame(['a'], $definitions->decisionServices[0]->outputDecisions);
        self::assertFalse($model->hasValidationErrors());
    }

    public function testModelSerializationRoundTrip(): void
    {
        $model = Dmn::fromFile(self::FIXTURES . '/fees.dmn');

        $serialized = serialize($model);
        $copy = unserialize($serialized);

        self::assertInstanceOf(\OpenWerk\DecisionModelAndNotation\DmnModel::class, $copy);

        $result = $copy->evaluateDecision('Discounted Fee', [
            'applicant' => ['age' => 34, 'income' => 51_000],
        ]);

        self::assertSame('90', self::str($result->feelValue()));
    }
}
