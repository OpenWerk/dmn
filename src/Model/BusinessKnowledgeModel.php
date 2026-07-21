<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * A business knowledge model: reusable decision logic with formal
 * parameters, invocable from FEEL expressions (`PMT(amount, rate, term)`).
 */
final class BusinessKnowledgeModel
{
    /**
     * @param list<string> $requiredKnowledge ids of business knowledge models this model invokes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?Variable $variable = null,
        public readonly array $requiredKnowledge = [],
        public readonly ?FunctionDefinition $logic = null,
    ) {
    }

    /**
     * The name this knowledge model's function is bound to in dependent
     * scopes.
     */
    public function resultName(): string
    {
        return $this->variable->name ?? $this->name;
    }
}
