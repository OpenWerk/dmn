<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

use OpenWerk\DecisionModelAndNotation\Feel\Ast\UnaryTests;

/**
 * A unary-tests expression: rule input entries, input values and output
 * values. The AST is null when the text failed to parse (a validation
 * message exists in that case).
 */
final class UnaryTestsExpression
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $text,
        public readonly ?UnaryTests $ast,
    ) {
    }
}
