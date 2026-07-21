<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * A DMNDI diagram (`dmndi:DMNDiagram`): read-only layout metadata for
 * renderers and tooling. Diagram content never affects evaluation.
 */
final class Diagram
{
    /**
     * @param list<DiagramShape> $shapes
     * @param list<DiagramEdge>  $edges
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $name,
        public readonly ?float $width = null,
        public readonly ?float $height = null,
        public readonly array $shapes = [],
        public readonly array $edges = [],
    ) {
    }
}
