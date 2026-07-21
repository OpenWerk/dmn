<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * A boxed context expression: named entries evaluated in order (each seeing
 * the previous ones), with an optional final result entry (no variable).
 */
final class BoxedContext implements Expression
{
    /**
     * @param list<array{string|null, Expression|null}> $entries [variable name (null = result entry), expression]
     */
    public function __construct(
        public readonly ?string $id,
        public readonly array $entries,
    ) {
    }
}
