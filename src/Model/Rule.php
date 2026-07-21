<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * A decision-table rule (row).
 */
final class Rule
{
    /**
     * @param list<UnaryTestsExpression> $inputEntries
     * @param list<LiteralExpression>    $outputEntries
     */
    public function __construct(
        public readonly ?string $id,
        public readonly array $inputEntries,
        public readonly array $outputEntries,
        public readonly ?string $description = null,
    ) {
    }
}
