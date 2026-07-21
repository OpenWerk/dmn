<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tck;

/**
 * @internal
 */
final class CaseResult
{
    /**
     * @param string       $suite    e.g. "compliance-level-2/0004-simpletable-U"
     * @param list<string> $details  human-readable mismatch/error descriptions
     * @param ?string      $testFile the test cases file the case came from,
     *                               without extension ("0004-simpletable-U-test-01")
     */
    public function __construct(
        public readonly string $suite,
        public readonly string $caseId,
        public readonly CaseOutcome $outcome,
        public readonly array $details = [],
        public readonly ?string $testFile = null,
    ) {
    }
}
