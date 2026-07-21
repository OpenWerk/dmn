<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

final class InputData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?Variable $variable = null,
    ) {
    }

    /**
     * The name callers supply this input under, and the name it is bound to
     * in decision scopes.
     */
    public function inputName(): string
    {
        return $this->variable->name ?? $this->name;
    }
}
