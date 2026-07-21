<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Parser;

use OpenWerk\DecisionModelAndNotation\Dmn;
use OpenWerk\DecisionModelAndNotation\DmnModel;
use PHPUnit\Framework\TestCase;

/**
 * DMNDI diagrams are exposed as read-only model metadata: shapes with
 * bounds, edges with waypoints, matched by local name across the
 * version-specific DMNDI/DC/DI namespaces. Diagram content never affects
 * evaluation.
 */
final class DmndiTest extends TestCase
{
    public function testFixtureDiagramIsExposed(): void
    {
        $diagrams = Dmn::fromFile(__DIR__ . '/../fixtures/fees.dmn')->diagrams();

        self::assertCount(1, $diagrams);
        self::assertSame('diagram', $diagrams[0]->id);
        self::assertCount(1, $diagrams[0]->shapes);

        $shape = $diagrams[0]->shapes[0];

        self::assertSame('riskCategory', $shape->dmnElementRef);
        self::assertNotNull($shape->bounds);
        self::assertSame([100.0, 100.0, 180.0, 80.0], [
            $shape->bounds->x,
            $shape->bounds->y,
            $shape->bounds->width,
            $shape->bounds->height,
        ]);
        self::assertSame([], $diagrams[0]->edges);
    }

    public function testDiagramsParseAcrossDiNamespaceVersions(): void
    {
        $diagrams = $this->versionedModel()->diagrams();

        self::assertCount(1, $diagrams);

        $diagram = $diagrams[0];

        self::assertSame(['dg1', 'Page 1', 800.0, 600.0], [
            $diagram->id,
            $diagram->name,
            $diagram->width,
            $diagram->height,
        ]);
        self::assertTrue($diagram->shapes[0]->isCollapsed);
        self::assertSame(10.5, $diagram->shapes[0]->bounds?->x);

        $edge = $diagram->edges[0];

        self::assertSame('req1', $edge->dmnElementRef);
        self::assertSame([[1.0, 2.0], [3.5, 4.0]], array_map(
            static fn($waypoint): array => [$waypoint->x, $waypoint->y],
            $edge->waypoints,
        ));
    }

    public function testDiagramMetadataDoesNotDisturbValidationOrEvaluation(): void
    {
        $model = $this->versionedModel();

        self::assertSame([], $model->validationMessages());
        self::assertFalse($model->evaluateDecision('D')->hasErrors());
    }

    public function testModelsWithoutDmndiReportNoDiagrams(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20230324/MODEL/" id="t" name="T" namespace="urn:t">
              <decision id="d" name="D"><literalExpression><text>1</text></literalExpression></decision>
            </definitions>
            XML;

        self::assertSame([], Dmn::fromString($xml)->diagrams());
    }

    public function testDiagramsSurviveTheCacheRoundTrip(): void
    {
        $model = Dmn::fromFile(__DIR__ . '/../fixtures/fees.dmn');
        $cached = unserialize(serialize($model));

        self::assertInstanceOf(DmnModel::class, $cached);
        self::assertEquals($model->diagrams(), $cached->diagrams());
    }

    private function versionedModel(): DmnModel
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <definitions xmlns="https://www.omg.org/spec/DMN/20191111/MODEL/"
                         xmlns:dmndi="https://www.omg.org/spec/DMN/20191111/DMNDI/"
                         xmlns:dc="http://www.omg.org/spec/DMN/20180521/DC/"
                         xmlns:di="http://www.omg.org/spec/DMN/20180521/DI/"
                         id="t" name="T" namespace="urn:t">
              <decision id="d" name="D"><literalExpression><text>1</text></literalExpression></decision>
              <dmndi:DMNDI>
                <dmndi:DMNDiagram id="dg1" name="Page 1">
                  <dmndi:Size width="800" height="600"/>
                  <dmndi:DMNShape id="s1" dmnElementRef="d" isCollapsed="true">
                    <dc:Bounds x="10.5" y="20" width="140" height="60"/>
                  </dmndi:DMNShape>
                  <dmndi:DMNEdge id="e1" dmnElementRef="req1">
                    <di:waypoint x="1" y="2"/>
                    <di:waypoint x="3.5" y="4"/>
                  </dmndi:DMNEdge>
                </dmndi:DMNDiagram>
              </dmndi:DMNDI>
            </definitions>
            XML;

        return Dmn::fromString($xml);
    }
}
