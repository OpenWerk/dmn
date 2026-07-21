<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * @internal
 */
final class StringLiteral implements Node
{
    public function __construct(public readonly string $value)
    {
    }
}
