<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation;

/**
 * One declared parameter of an invocable: the name callers supply the value
 * under, and its declared type reference (a FEEL base type or an item
 * definition name), when one is declared.
 */
final class InvocableParameter
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $typeRef = null,
    ) {
    }
}
