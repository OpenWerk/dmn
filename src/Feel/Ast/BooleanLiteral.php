<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * @internal
 */
final class BooleanLiteral implements Node
{
    public function __construct(public readonly bool $value)
    {
    }
}
