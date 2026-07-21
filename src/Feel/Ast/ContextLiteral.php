<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * `{key: value, "other key": value}` — entries evaluate in order and each
 * entry sees the previous entries in scope.
 *
 * @phpstan-type ContextEntry array{string, Node}
 *
 * @internal
 */
final class ContextLiteral implements Node
{
    /**
     * @param list<array{string, Node}> $entries [key, value expression] pairs
     */
    public function __construct(public readonly array $entries)
    {
    }
}
