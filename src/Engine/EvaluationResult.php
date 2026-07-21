<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Engine;

use OpenWerk\DecisionModelAndNotation\Diagnostics\Diagnostic;
use OpenWerk\DecisionModelAndNotation\Diagnostics\Severity;

/**
 * The typed result of a decision evaluation.
 */
final class EvaluationResult
{
    /**
     * @param list<Diagnostic>  $diagnostics
     * @param list<MatchedRule> $matchedRules
     */
    public function __construct(
        private readonly string $decisionName,
        private readonly mixed $feelValue,
        private readonly array $diagnostics,
        private readonly array $matchedRules,
    ) {
    }

    public function decisionName(): string
    {
        return $this->decisionName;
    }

    /**
     * The decision result as PHP values: brick/math BigDecimal for numbers,
     * the FEEL temporal wrapper objects, arrays for lists and contexts,
     * native bool/string/null otherwise.
     */
    public function value(): mixed
    {
        return OutputConverter::toPhp($this->feelValue);
    }

    /**
     * The raw FEEL runtime value (numbers as FeelNumber).
     */
    public function feelValue(): mixed
    {
        return $this->feelValue;
    }

    /**
     * @return list<Diagnostic>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function hasErrors(): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->severity === Severity::Error) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which rules matched, per decision, in evaluation order — the audit
     * trail for explainability.
     *
     * @return list<MatchedRule>
     */
    public function matchedRules(): array
    {
        return $this->matchedRules;
    }

    /**
     * The matched rules grouped by the decision (or business knowledge
     * model) whose table produced them, preserving evaluation order within
     * each group.
     *
     * @return array<string, list<MatchedRule>>
     */
    public function matchedRulesByDecision(): array
    {
        $grouped = [];

        foreach ($this->matchedRules as $rule) {
            $grouped[$rule->decision][] = $rule;
        }

        return $grouped;
    }

    /**
     * The matched rules of one decision (or business knowledge model),
     * looked up by name; empty when its table recorded no hits.
     *
     * @return list<MatchedRule>
     */
    public function matchedRulesFor(string $decisionName): array
    {
        return $this->matchedRulesByDecision()[$decisionName] ?? [];
    }
}
