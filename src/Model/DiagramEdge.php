<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * A diagram edge (`dmndi:DMNEdge`): the visual for one requirement or
 * association, as a polyline of waypoints.
 */
final class DiagramEdge
{
    /**
     * @param list<DiagramWaypoint> $waypoints
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $dmnElementRef,
        public readonly array $waypoints = [],
    ) {
    }
}
