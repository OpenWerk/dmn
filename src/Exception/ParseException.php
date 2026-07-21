<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Exception;

/**
 * Thrown when a DMN document cannot be read at all (I/O failure, XML that is
 * not well-formed, or a root element that is not a DMN definitions element).
 *
 * Recoverable model problems do not throw; they are reported as
 * {@see \OpenWerk\DecisionModelAndNotation\Validation\ValidationMessage} on the parsed model.
 */
final class ParseException extends \RuntimeException implements DmnException
{
    public function __construct(
        string $message,
        public readonly ?int $xmlLine = null,
    ) {
        parent::__construct($xmlLine === null ? $message : sprintf('%s (line %d)', $message, $xmlLine));
    }
}
