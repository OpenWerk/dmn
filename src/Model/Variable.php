<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * An information item variable declaration (`<variable>`).
 */
final class Variable
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $name,
        public readonly ?string $typeRef = null,
    ) {
    }
}
