<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Exception;

/**
 * Thrown by the FEEL lexer/parser when an expression or unary test cannot be
 * parsed. During model parsing this is caught and converted into a validation
 * message; it only escapes when the FEEL parser is used directly.
 */
final class FeelParseException extends \RuntimeException implements DmnException
{
    public function __construct(
        string $message,
        public readonly string $source,
        public readonly int $position,
    ) {
        parent::__construct(sprintf('%s in FEEL %s at offset %d', $message, var_export($source, true), $position));
    }
}
