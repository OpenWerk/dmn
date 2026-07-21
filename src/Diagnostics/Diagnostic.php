<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Diagnostics;

/**
 * A single evaluation-time diagnostic. FEEL evaluation errors never throw
 * through the public API — they evaluate to null and are reported here.
 */
final class Diagnostic implements \Stringable
{
    public const string FEEL_ERROR = 'feel.error';
    public const string FEEL_UNKNOWN_NAME = 'feel.unknown-name';
    public const string FEEL_UNKNOWN_FUNCTION = 'feel.unknown-function';
    public const string FEEL_INVALID_EXPRESSION = 'feel.invalid-expression';
    public const string TABLE_NO_MATCH = 'table.no-match';
    public const string TABLE_UNIQUE_VIOLATION = 'table.unique-violation';
    public const string TABLE_ANY_VIOLATION = 'table.any-violation';
    public const string TABLE_PRIORITY_UNDEFINED = 'table.priority-undefined';
    public const string TABLE_INPUT_VALUES_VIOLATION = 'table.input-values-violation';
    public const string TABLE_OUTPUT_VALUES_VIOLATION = 'table.output-values-violation';
    public const string TABLE_AGGREGATION_ERROR = 'table.aggregation-error';
    public const string TABLE_STRUCTURE_ERROR = 'table.structure-error';
    public const string DECISION_MISSING_INPUT = 'decision.missing-input';
    public const string DECISION_NO_EXPRESSION = 'decision.no-expression';
    public const string DECISION_UNSUPPORTED_REQUIREMENT = 'decision.unsupported-requirement';
    public const string DECISION_CYCLE = 'decision.cycle';

    public function __construct(
        public readonly Severity $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $decision = null,
    ) {
    }

    public function __toString(): string
    {
        $prefix = $this->decision === null ? '' : sprintf('[%s] ', $this->decision);

        return sprintf('%s: %s%s (%s)', $this->severity->value, $prefix, $this->message, $this->code);
    }
}
