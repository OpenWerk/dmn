<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;

/**
 * @internal
 */
final class NumberLiteral implements Node
{
    public function __construct(public readonly FeelNumber $value)
    {
    }
}
