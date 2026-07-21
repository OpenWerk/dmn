<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * A temporal at-literal such as `@"2027-01-01"` (DMN 1.3+ FEEL syntax).
 *
 * @internal
 */
final class AtLiteral implements Node
{
    public function __construct(public readonly string $text)
    {
    }
}
