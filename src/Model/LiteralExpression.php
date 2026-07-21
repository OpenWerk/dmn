<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

use OpenWerk\DecisionModelAndNotation\Feel\Ast\Node;

/**
 * A FEEL literal expression. The AST is parsed once at model-parse time
 * (parse once, evaluate hot); it is null when the text failed to parse, in
 * which case a validation message exists and evaluation yields null plus a
 * diagnostic.
 */
final class LiteralExpression implements Expression
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $text,
        public readonly ?Node $ast,
        public readonly ?string $expressionLanguage = null,
        public readonly ?string $typeRef = null,
    ) {
    }
}
