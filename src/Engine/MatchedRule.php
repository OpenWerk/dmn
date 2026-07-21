<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Engine;

/**
 * Explainability record: one decision-table rule that matched during an
 * evaluation.
 *
 * `$decision` names the model element whose logic contains the table — a
 * decision or a business knowledge model; `$tableId` identifies the table
 * itself, when the model declares an id for it.
 */
final class MatchedRule
{
    public function __construct(
        public readonly string $decision,
        public readonly int $ruleIndex,
        public readonly ?string $ruleId = null,
        public readonly ?string $tableId = null,
    ) {
    }
}
