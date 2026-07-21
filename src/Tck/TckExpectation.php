<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tck;

/**
 * One resultNode of a TCK test case: the decision to evaluate and the
 * expected FEEL value.
 *
 * @internal
 */
final class TckExpectation
{
    public function __construct(
        public readonly string $decision,
        public readonly mixed $expected,
    ) {
    }
}
