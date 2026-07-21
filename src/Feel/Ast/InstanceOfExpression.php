<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * `value instance of type`.
 *
 * @internal
 */
final class InstanceOfExpression implements Node
{
    public function __construct(
        public readonly Node $value,
        public readonly TypeNode $type,
    ) {
    }
}
