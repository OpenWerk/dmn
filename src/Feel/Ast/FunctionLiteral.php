<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * An anonymous FEEL function `function(a, b) a + b`. External functions
 * (`function(...) external {...}`) are recognized but not evaluable
 * (documented deviation: no Java/PMML bindings).
 *
 * @internal
 */
final class FunctionLiteral implements Node
{
    /**
     * @param list<string>         $parameters
     * @param array<int, TypeNode> $parameterTypes declared parameter types by position (sparse)
     */
    public function __construct(
        public readonly array $parameters,
        public readonly ?Node $body,
        public readonly bool $external = false,
        public readonly array $parameterTypes = [],
    ) {
    }
}
