<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * `if condition then a else b`. A condition that is not `true` selects the
 * else branch (FEEL treats null/non-boolean conditions as not-true).
 *
 * @internal
 */
final class IfExpression implements Node
{
    public function __construct(
        public readonly Node $condition,
        public readonly Node $then,
        public readonly Node $else,
    ) {
    }
}
