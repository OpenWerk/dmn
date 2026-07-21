<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * Marker interface for FEEL AST nodes. Nodes are immutable and serializable,
 * so parsed models can be cached (parse once, evaluate hot).
 *
 * @internal
 */
interface Node
{
}
