<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * A diagram shape (`dmndi:DMNShape`): the visual for one DRG element,
 * referenced through its model element id.
 */
final class DiagramShape
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $dmnElementRef,
        public readonly ?DiagramBounds $bounds = null,
        public readonly bool $isCollapsed = false,
    ) {
    }
}
