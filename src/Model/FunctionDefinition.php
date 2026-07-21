<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * A function definition: the encapsulated logic of a business knowledge
 * model, or a boxed function-definition expression evaluating to a function
 * value.
 */
final class FunctionDefinition implements Expression
{
    /**
     * @param list<Variable> $parameters
     */
    public function __construct(
        public readonly ?string $id,
        public readonly array $parameters,
        public readonly ?Expression $body,
    ) {
    }
}
