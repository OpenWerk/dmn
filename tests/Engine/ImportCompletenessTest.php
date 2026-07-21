<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Engine;

use Brick\Math\BigDecimal;
use OpenWerk\DecisionModelAndNotation\Diagnostics\Severity;
use OpenWerk\DecisionModelAndNotation\Dmn;
use PHPUnit\Framework\TestCase;

/**
 * The import edge cases: requirement references chained through multiple
 * import levels, one namespace imported under several aliases, and non-DMN
 * imports staying structured metadata.
 */
final class ImportCompletenessTest extends TestCase
{
    private const string IMPORTS = __DIR__ . '/../fixtures/imports';

    public function testRequirementsChainThroughMultipleImportLevels(): void
    {
        // deep.dmn imports main.dmn as "mid", which imports base.dmn as
        // "lib"; deep references base's decision directly by namespace and
        // reads it as the chained qualified name mid.lib.Base Level.
        $model = Dmn::fromFile(self::IMPORTS . '/deep.dmn');

        self::assertSame([], $model->validationMessages());

        $result = $model->evaluateDecision('Chained', [], ['urn:import-base' => ['points' => 7]]);

        self::assertFalse($result->hasErrors());

        $value = $result->value();
        self::assertInstanceOf(BigDecimal::class, $value);
        self::assertSame('102', (string) $value);

        // The audit trail qualifies through every import level.
        self::assertSame(['mid.lib.Base Level'], array_keys($result->matchedRulesByDecision()));
    }

    public function testOneNamespaceImportedUnderTwoAliases(): void
    {
        $model = Dmn::fromFile(self::IMPORTS . '/aliased.dmn');

        self::assertSame([], $model->validationMessages());

        $result = $model->evaluateDecision('Double Aliased', [], ['urn:import-base' => ['points' => 8]]);

        self::assertFalse($result->hasErrors());

        $value = $result->value();
        self::assertInstanceOf(BigDecimal::class, $value);
        self::assertSame('4', (string) $value);
    }

    public function testNonDmnImportsStayStructuredMetadata(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <import namespace="urn:types" name="xsd"
                      importType="http://www.w3.org/2001/XMLSchema" locationURI="types.xsd"/>
              <decision id="d" name="D"><literalExpression><text>1</text></literalExpression></decision>
            </definitions>
            XML;

        $model = Dmn::fromString($xml);
        $imports = $model->imports();

        self::assertCount(1, $imports);
        self::assertSame(
            ['urn:types', 'xsd', 'http://www.w3.org/2001/XMLSchema', 'types.xsd'],
            [$imports[0]->namespace, $imports[0]->name, $imports[0]->importType, $imports[0]->locationUri],
        );

        // Informational only: no warnings, no errors, evaluation untouched.
        self::assertSame(
            [Severity::Info],
            array_map(static fn($message) => $message->severity, $model->validationMessages()),
        );
        self::assertFalse($model->hasValidationErrors());
        self::assertFalse($model->evaluateDecision('D')->hasErrors());
    }

    public function testUnknownNamespaceReferencesWarnAndAreSkipped(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <decision id="d" name="D">
                <informationRequirement><requiredDecision href="urn:nowhere#x"/></informationRequirement>
                <literalExpression><text>1</text></literalExpression>
              </decision>
            </definitions>
            XML;

        $model = Dmn::fromString($xml);

        $skipped = array_filter(
            array_map(static fn($message): string => (string) $message, $model->validationMessages()),
            static fn(string $message): bool => str_contains(
                $message,
                'does not match the model or a resolved import',
            ),
        );

        self::assertCount(1, $skipped);
        self::assertFalse($model->hasValidationErrors());
    }
}
