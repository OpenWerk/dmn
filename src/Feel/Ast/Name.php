<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * A variable reference. FEEL names may contain spaces (`monthly salary`);
 * multi-word names are resolved against the names known in scope at parse
 * time and stored whitespace-normalized.
 *
 * @internal
 */
final class Name implements Node
{
    public function __construct(public readonly string $name)
    {
    }
}
