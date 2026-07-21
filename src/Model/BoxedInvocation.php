<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * A boxed invocation: calls an invocable (usually a business knowledge
 * model) with named parameter bindings.
 */
final class BoxedInvocation implements Expression
{
    /**
     * @param list<array{string, Expression|null}> $bindings [parameter name, expression]
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?Expression $callee,
        public readonly array $bindings,
    ) {
    }
}
