<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Parser;

use OpenWerk\DecisionModelAndNotation\Model\Definitions;
use OpenWerk\DecisionModelAndNotation\Validation\ValidationMessage;

/**
 * Resolves a DMN model import to its parsed definitions by model namespace.
 */
interface ImportResolver
{
    public function resolve(string $namespace): ?Definitions;

    /**
     * Validation messages collected while resolving (parse findings of the
     * imported models). Draining resets the buffer.
     *
     * @return list<ValidationMessage>
     */
    public function drainMessages(): array;
}
