<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * A function invocation. The callee is usually a {@see Name} (built-in or
 * scope function) but may be any expression yielding a function value.
 * Arguments are positional or named — never mixed.
 *
 * @internal
 */
final class FunctionCall implements Node
{
    /**
     * @param list<Node>          $arguments
     * @param array<string, Node> $namedArguments
     */
    public function __construct(
        public readonly Node $callee,
        public readonly array $arguments = [],
        public readonly array $namedArguments = [],
    ) {
    }

    public function calleeName(): ?string
    {
        return $this->callee instanceof Name ? $this->callee->name : null;
    }
}
