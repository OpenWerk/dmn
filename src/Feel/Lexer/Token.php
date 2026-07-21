<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Lexer;

/**
 * @internal
 */
final class Token
{
    /**
     * @param string $text for string literals the decoded value, otherwise the raw lexeme
     */
    public function __construct(
        public readonly TokenType $type,
        public readonly string $text,
        public readonly int $position,
    ) {
    }
}
