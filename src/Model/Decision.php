<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

final class Decision
{
    /**
     * @param list<string> $requiredDecisions ids of decisions this decision requires
     * @param list<string> $requiredInputs    ids of input data this decision requires
     * @param list<string> $requiredKnowledge ids of business knowledge models this decision invokes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?Variable $variable,
        public readonly ?Expression $expression,
        public readonly array $requiredDecisions = [],
        public readonly array $requiredInputs = [],
        public readonly array $requiredKnowledge = [],
    ) {
    }

    /**
     * The name this decision's result is bound to in dependent scopes.
     */
    public function resultName(): string
    {
        return $this->variable->name ?? $this->name;
    }
}
