<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * A decision-table output column. Output values define the priority order
 * used by the PRIORITY and OUTPUT ORDER hit policies.
 */
final class OutputClause
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $name,
        public readonly ?string $label,
        public readonly ?string $typeRef,
        public readonly ?UnaryTestsExpression $outputValues = null,
        public readonly ?LiteralExpression $defaultOutputEntry = null,
    ) {
    }

    /**
     * The key under which this output's value appears in compound results.
     */
    public function resultKey(int $index): string
    {
        return $this->name ?? $this->label ?? 'output ' . ($index + 1);
    }
}
