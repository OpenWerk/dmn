<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * A reusable data type definition. Declared types resolve against these
 * definitions during evaluation, and `allowedValues` constraints are
 * enforced as part of type conformance.
 */
final class ItemDefinition
{
    /**
     * @param list<ItemDefinition>  $components
     * @param ?UnaryTestsExpression $allowedValues         the allowed-values constraint, parsed once at
     *                                                     model load like every other unary-tests text
     * @param ?string               $functionOutputTypeRef `<functionItem outputTypeRef>`: the type an
     *                                                     invocation result must conform to
     */
    public function __construct(
        public readonly ?string $id,
        public readonly string $name,
        public readonly ?string $typeRef = null,
        public readonly bool $isCollection = false,
        public readonly array $components = [],
        public readonly ?UnaryTestsExpression $allowedValues = null,
        public readonly ?string $functionOutputTypeRef = null,
    ) {
    }
}
