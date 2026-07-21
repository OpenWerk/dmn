<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * @internal
 */
final class ListLiteral implements Node
{
    /**
     * @param list<Node> $elements
     */
    public function __construct(public readonly array $elements)
    {
    }
}
