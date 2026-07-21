<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * @internal
 */
final class Binary implements Node
{
    public function __construct(
        public readonly BinaryOp $op,
        public readonly Node $left,
        public readonly Node $right,
    ) {
    }
}
