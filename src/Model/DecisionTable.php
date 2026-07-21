<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

use OpenWerk\DecisionModelAndNotation\Feel\Ast\Name;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\PathExpression;

final class DecisionTable implements Expression
{
    /**
     * @param list<InputClause>  $inputs
     * @param list<OutputClause> $outputs
     * @param list<Rule>         $rules
     */
    public function __construct(
        public readonly ?string $id,
        public readonly HitPolicy $hitPolicy,
        public readonly ?BuiltinAggregator $aggregation,
        public readonly array $inputs,
        public readonly array $outputs,
        public readonly array $rules,
    ) {
    }

    /**
     * The implicit formal parameters of a decision table used as business
     * knowledge model logic without declared parameters: the distinct root
     * names of its input expressions, in order (DMN 10.4).
     *
     * @return list<string>
     */
    public function implicitParameterNames(): array
    {
        $names = [];

        foreach ($this->inputs as $input) {
            $node = $input->expression->ast;

            while ($node instanceof PathExpression) {
                $node = $node->base;
            }

            if ($node instanceof Name && !in_array($node->name, $names, true)) {
                $names[] = $node->name;
            }
        }

        return $names;
    }
}
