<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * One point along a diagram edge (`di:waypoint`).
 */
final class DiagramWaypoint
{
    public function __construct(
        public readonly float $x,
        public readonly float $y,
    ) {
    }
}
