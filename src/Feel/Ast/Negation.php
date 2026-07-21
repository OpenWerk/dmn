<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * Arithmetic negation (unary minus).
 *
 * @internal
 */
final class Negation implements Node
{
    public function __construct(public readonly Node $operand)
    {
    }
}
