<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Parser;

use OpenWerk\DecisionModelAndNotation\Model\Definitions;
use OpenWerk\DecisionModelAndNotation\Validation\ValidationMessage;

final class ParseResult
{
    /**
     * @param list<ValidationMessage> $messages
     */
    public function __construct(
        public readonly Definitions $definitions,
        public readonly array $messages,
    ) {
    }
}
