<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Engine;

use OpenWerk\DecisionModelAndNotation\Diagnostics\Diagnostic;
use OpenWerk\DecisionModelAndNotation\Diagnostics\DiagnosticCollector;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\BooleanLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\ExpressionTest;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Negation;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Node;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\NumberLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\StringLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Evaluator\FeelCompiler;
use OpenWerk\DecisionModelAndNotation\Feel\Evaluator\FeelEvaluator;
use OpenWerk\DecisionModelAndNotation\Feel\Evaluator\Scope;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ops;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;
use OpenWerk\DecisionModelAndNotation\Model\BuiltinAggregator;
use OpenWerk\DecisionModelAndNotation\Model\DecisionTable;
use OpenWerk\DecisionModelAndNotation\Model\HitPolicy;
use OpenWerk\DecisionModelAndNotation\Model\LiteralExpression;
use OpenWerk\DecisionModelAndNotation\Model\Rule;

/**
 * Evaluates a decision table under all seven hit policies (U, A, P, F, R,
 * O, C) including the COLLECT aggregators.
 *
 * Hit-policy violations behave per specification: UNIQUE with two hits,
 * ANY with differing outputs, PRIORITY without output values and allowed-
 * value violations all yield null plus an error diagnostic.
 *
 * @internal
 */
final class DecisionTableEvaluator
{
    /**
     * Compiled matchers of a table's input entries, one closure per rule
     * and column (null where the entry failed to parse), resolved once per
     * table per process. Keyed by the table, which lives in an immutable
     * parsed model.
     *
     * @var \WeakMap<DecisionTable, list<list<(\Closure(mixed, Scope, FeelEvaluator): ?bool)|null>>>|null
     */
    private static ?\WeakMap $matchers = null;

    public function __construct(
        private readonly FeelEvaluator $evaluator,
        private readonly DiagnosticCollector $diagnostics,
    ) {
    }

    /**
     * @return list<list<(\Closure(mixed, Scope, FeelEvaluator): ?bool)|null>>
     */
    private static function matchers(DecisionTable $table): array
    {
        $cache = self::$matchers ??= new \WeakMap();

        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $matrix = [];

        foreach ($table->rules as $rule) {
            $row = [];

            foreach ($rule->inputEntries as $entry) {
                $row[] = $entry->ast === null ? null : FeelCompiler::compiledTests($entry->ast);
            }

            $matrix[] = $row;
        }

        return $cache[$table] = $matrix;
    }

    /**
     * @return array{mixed, list<array{int, ?string}>} result value plus
     *                                                  matched [1-based rule index, rule id] pairs
     */
    public function evaluate(DecisionTable $table, Scope $scope): array
    {
        if ($table->outputs === []) {
            $this->diagnostics->error(Diagnostic::TABLE_STRUCTURE_ERROR, 'decision table has no output clause');

            return [null, []];
        }

        $inputValues = $this->inputValues($table, $scope);

        if ($inputValues === null) {
            return [null, []];
        }

        $matches = $this->matchingRules($table, $inputValues, $scope);
        $matchedRefs = [];

        foreach ($matches as $ruleIndex) {
            $matchedRefs[] = [$ruleIndex + 1, $table->rules[$ruleIndex]->id];
        }

        if ($matches === []) {
            return [$this->defaultResult($table, $scope), []];
        }

        $ruleOutputs = [];

        foreach ($matches as $ruleIndex) {
            $ruleOutputs[] = $this->outputValues($table, $table->rules[$ruleIndex], $scope);
        }

        if (!$this->checkOutputValues($table, $matches, $ruleOutputs, $scope)) {
            return [null, $matchedRefs];
        }

        $value = $this->applyHitPolicy($table, $matches, $ruleOutputs, $scope);

        return [$value, $matchedRefs];
    }

    /**
     * @return list<mixed>|null null when an allowed-input-values constraint is violated
     */
    private function inputValues(DecisionTable $table, Scope $scope): ?array
    {
        $values = [];

        foreach ($table->inputs as $index => $input) {
            $value = $this->literal($input->expression, $scope);

            if ($input->inputValues?->ast !== null) {
                $allowed = $this->evaluator->test($input->inputValues->ast, $value, $scope);

                if ($allowed !== true) {
                    $this->diagnostics->error(
                        Diagnostic::TABLE_INPUT_VALUES_VIOLATION,
                        sprintf(
                            'value of input %d (%s) is not in the allowed input values %s',
                            $index + 1,
                            $input->expression->text,
                            var_export($input->inputValues->text, true),
                        ),
                    );

                    return null;
                }
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * @param list<mixed> $inputValues
     *
     * @return list<int> 0-based indexes of matching rules
     */
    private function matchingRules(DecisionTable $table, array $inputValues, Scope $scope): array
    {
        $matches = [];

        // One `?`-bound scope per input column: the input value is the same
        // for every rule, only the entries differ.
        $columnScopes = [];

        foreach ($inputValues as $inputIndex => $value) {
            $columnScopes[$inputIndex] = $scope->child(['?' => $value]);
        }

        $matchers = self::matchers($table);

        foreach ($table->rules as $ruleIndex => $rule) {
            if (
                \count($rule->inputEntries) !== \count($table->inputs)
                || \count($rule->outputEntries) !== \count($table->outputs)
            ) {
                $this->diagnostics->error(
                    Diagnostic::TABLE_STRUCTURE_ERROR,
                    sprintf('rule %d does not cover every input/output column; the rule is skipped', $ruleIndex + 1),
                );

                continue;
            }

            $matched = true;

            foreach ($table->inputs as $inputIndex => $input) {
                $matcher = $matchers[$ruleIndex][$inputIndex] ?? null;

                if ($matcher === null) {
                    $entry = $rule->inputEntries[$inputIndex];
                    $this->diagnostics->error(
                        Diagnostic::FEEL_INVALID_EXPRESSION,
                        sprintf(
                            'rule %d input entry %d (%s) is not parseable; the rule cannot match',
                            $ruleIndex + 1,
                            $inputIndex + 1,
                            var_export($entry->text, true),
                        ),
                    );
                    $matched = false;
                    break;
                }

                if ($matcher($inputValues[$inputIndex], $columnScopes[$inputIndex], $this->evaluator) !== true) {
                    $matched = false;
                    break;
                }
            }

            if ($matched) {
                $matches[] = $ruleIndex;
            }
        }

        return $matches;
    }

    /**
     * @return list<mixed> one value per output clause
     */
    private function outputValues(DecisionTable $table, Rule $rule, Scope $scope): array
    {
        $values = [];

        foreach ($table->outputs as $outputIndex => $output) {
            $values[] = $this->literal($rule->outputEntries[$outputIndex], $scope);
        }

        return $values;
    }

    /**
     * Enforces the allowed output values of every output clause.
     *
     * @param list<int>         $matches
     * @param list<list<mixed>> $ruleOutputs
     */
    private function checkOutputValues(DecisionTable $table, array $matches, array $ruleOutputs, Scope $scope): bool
    {
        foreach ($table->outputs as $outputIndex => $output) {
            if ($output->outputValues?->ast === null) {
                continue;
            }

            foreach ($ruleOutputs as $matchIndex => $values) {
                if ($this->evaluator->test($output->outputValues->ast, $values[$outputIndex], $scope) !== true) {
                    $this->diagnostics->error(
                        Diagnostic::TABLE_OUTPUT_VALUES_VIOLATION,
                        sprintf(
                            'rule %d output %d is not in the allowed output values %s',
                            $matches[$matchIndex] + 1,
                            $outputIndex + 1,
                            var_export($output->outputValues->text, true),
                        ),
                    );

                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param list<int>         $matches
     * @param list<list<mixed>> $ruleOutputs
     */
    private function applyHitPolicy(DecisionTable $table, array $matches, array $ruleOutputs, Scope $scope): mixed
    {
        switch ($table->hitPolicy) {
            case HitPolicy::Unique:
                if (\count($matches) > 1) {
                    $this->diagnostics->error(
                        Diagnostic::TABLE_UNIQUE_VIOLATION,
                        sprintf(
                            'hit policy UNIQUE violated: rules %s all match',
                            implode(', ', array_map(static fn(int $index): string => (string) ($index + 1), $matches)),
                        ),
                    );

                    return null;
                }

                return $this->compose($table, $ruleOutputs[0]);

            case HitPolicy::Any:
                $first = $this->compose($table, $ruleOutputs[0]);

                foreach ($ruleOutputs as $values) {
                    if (Ops::equals($this->compose($table, $values), $first) !== true) {
                        $this->diagnostics->error(
                            Diagnostic::TABLE_ANY_VIOLATION,
                            'hit policy ANY violated: matching rules produce different outputs',
                        );

                        return null;
                    }
                }

                return $this->compose($table, $ruleOutputs[0]);

            case HitPolicy::First:
                return $this->compose($table, $ruleOutputs[0]);

            case HitPolicy::Priority:
            case HitPolicy::OutputOrder:
                return $this->prioritized($table, $matches, $ruleOutputs, $scope);

            case HitPolicy::RuleOrder:
                return array_map(fn(array $values): mixed => $this->compose($table, $values), $ruleOutputs);

            case HitPolicy::Collect:
                $collected = array_map(fn(array $values): mixed => $this->compose($table, $values), $ruleOutputs);

                if ($table->aggregation === null) {
                    return $collected;
                }

                return $this->aggregate($table->aggregation, $collected);
        }
    }

    /**
     * PRIORITY and OUTPUT ORDER: order matched rules by the position of
     * their output values within the declared allowed output values.
     *
     * @param list<int>         $matches
     * @param list<list<mixed>> $ruleOutputs
     */
    private function prioritized(DecisionTable $table, array $matches, array $ruleOutputs, Scope $scope): mixed
    {
        $priorities = $this->outputPriorities($table, $scope);
        $ranked = [];

        foreach ($ruleOutputs as $matchIndex => $values) {
            $rank = [];

            foreach ($table->outputs as $outputIndex => $output) {
                $allowedValues = $priorities[$outputIndex];

                if ($allowedValues === null) {
                    // No (literal) output values on this output: it does not
                    // contribute to the ordering.
                    $rank[] = 0;
                    continue;
                }

                $position = $this->positionOf($values[$outputIndex], $allowedValues);

                if ($position === null) {
                    $this->diagnostics->error(
                        Diagnostic::TABLE_PRIORITY_UNDEFINED,
                        sprintf(
                            'output of rule %d has no position in the output values of output %d; '
                            . 'priority is undefined',
                            $matches[$matchIndex] + 1,
                            $outputIndex + 1,
                        ),
                    );

                    return null;
                }

                $rank[] = $position;
            }

            $ranked[] = ['rank' => $rank, 'order' => $matchIndex, 'values' => $values];
        }

        usort(
            $ranked,
            static fn(array $a, array $b): int => [$a['rank'], $a['order']] <=> [$b['rank'], $b['order']],
        );

        if ($table->hitPolicy === HitPolicy::Priority) {
            return $this->compose($table, $ranked[0]['values']);
        }

        return array_map(fn(array $entry): mixed => $this->compose($table, $entry['values']), $ranked);
    }

    /**
     * The ordered constant values of each output clause's allowed output
     * values — the priority order. An output without a plain list of literal
     * output values yields null and does not participate in the ordering;
     * when no output defines any, ordering degrades to rule order (with a
     * warning).
     *
     * @return list<list<mixed>|null>
     */
    private function outputPriorities(DecisionTable $table, Scope $scope): array
    {
        $priorities = [];
        $anyDefined = false;

        foreach ($table->outputs as $output) {
            $tests = $output->outputValues?->ast;

            if ($tests === null || $tests->negated) {
                $priorities[] = null;
                continue;
            }

            $values = [];

            foreach ($tests->tests as $test) {
                if (!$test instanceof ExpressionTest || !$this->isLiteral($test->expression)) {
                    $values = null;
                    break;
                }

                $values[] = $this->evaluator->evaluate($test->expression, $scope);
            }

            $priorities[] = $values;
            $anyDefined = $anyDefined || $values !== null;
        }

        if (!$anyDefined) {
            $this->diagnostics->warning(
                Diagnostic::TABLE_PRIORITY_UNDEFINED,
                sprintf(
                    'hit policy %s has no output values defining a priority order; falling back to rule order',
                    $table->hitPolicy->value,
                ),
            );
        }

        return $priorities;
    }

    /**
     * @param list<mixed> $allowedValues
     */
    private function positionOf(mixed $value, array $allowedValues): ?int
    {
        foreach ($allowedValues as $position => $allowed) {
            if (Ops::equals($value, $allowed) === true) {
                return $position;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $collected
     */
    private function aggregate(BuiltinAggregator $aggregator, array $collected): mixed
    {
        if ($aggregator === BuiltinAggregator::Count) {
            return FeelNumber::of(\count($collected));
        }

        foreach ($collected as $value) {
            if (!$value instanceof FeelNumber) {
                $this->diagnostics->error(
                    Diagnostic::TABLE_AGGREGATION_ERROR,
                    sprintf(
                        'aggregation %s requires number outputs, found %s',
                        $aggregator->value,
                        Ops::typeName($value),
                    ),
                );

                return null;
            }
        }

        /** @var list<FeelNumber> $collected */
        $result = $collected[0];

        foreach (\array_slice($collected, 1) as $value) {
            $result = match ($aggregator) {
                BuiltinAggregator::Sum => $result->plus($value),
                BuiltinAggregator::Min => $value->compareTo($result) < 0 ? $value : $result,
                default => $value->compareTo($result) > 0 ? $value : $result,
            };
        }

        return $result;
    }

    private function defaultResult(DecisionTable $table, Scope $scope): mixed
    {
        $hasDefaults = false;

        foreach ($table->outputs as $output) {
            if ($output->defaultOutputEntry !== null && $output->defaultOutputEntry->text !== '') {
                $hasDefaults = true;
                break;
            }
        }

        if (!$hasDefaults) {
            $this->diagnostics->info(Diagnostic::TABLE_NO_MATCH, 'no rule matched; the result is null');

            return null;
        }

        $this->diagnostics->info(Diagnostic::TABLE_NO_MATCH, 'no rule matched; the default output applies');

        $values = [];

        foreach ($table->outputs as $output) {
            $values[] = $output->defaultOutputEntry === null
                ? null
                : $this->literal($output->defaultOutputEntry, $scope);
        }

        $composed = $this->compose($table, $values);

        return $table->hitPolicy->isMultiHit() ? [$composed] : $composed;
    }

    /**
     * @param list<mixed> $values
     */
    private function compose(DecisionTable $table, array $values): mixed
    {
        if (\count($table->outputs) === 1) {
            return $values[0];
        }

        $composed = [];

        foreach ($table->outputs as $index => $output) {
            $composed[$output->resultKey($index)] = $values[$index];
        }

        return $composed;
    }

    private function literal(LiteralExpression $expression, Scope $scope): mixed
    {
        if ($expression->ast === null) {
            $this->diagnostics->error(
                Diagnostic::FEEL_INVALID_EXPRESSION,
                sprintf('expression %s is not evaluable; it yields null', var_export($expression->text, true)),
            );

            return null;
        }

        return $this->evaluator->evaluate($expression->ast, $scope);
    }

    private function isLiteral(Node $node): bool
    {
        if ($node instanceof Negation) {
            return $node->operand instanceof NumberLiteral;
        }

        return $node instanceof NumberLiteral
            || $node instanceof StringLiteral
            || $node instanceof BooleanLiteral;
    }
}
