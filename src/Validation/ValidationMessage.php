<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Validation;

use OpenWerk\DecisionModelAndNotation\Diagnostics\Severity;

/**
 * A parse-time / static-validation finding, positioned by XML line and the id
 * of the model element it concerns (where available).
 */
final class ValidationMessage implements \Stringable
{
    public function __construct(
        public readonly Severity $severity,
        public readonly string $message,
        public readonly ?string $elementId = null,
        public readonly ?int $line = null,
    ) {
    }

    public function __toString(): string
    {
        $location = [];

        if ($this->elementId !== null) {
            $location[] = 'element ' . $this->elementId;
        }

        if ($this->line !== null) {
            $location[] = 'line ' . $this->line;
        }

        $suffix = $location === [] ? '' : ' [' . implode(', ', $location) . ']';

        return sprintf('%s: %s%s', $this->severity->value, $this->message, $suffix);
    }
}
