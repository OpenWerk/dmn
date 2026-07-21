<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Validation;

use OpenWerk\DecisionModelAndNotation\Feel\Ast\AnyTest;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\AtLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Between;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Binary;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\BinaryOp;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\BooleanLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\ComparisonRange;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\ComparisonTest;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\ContextLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\ExpressionTest;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Filter;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\ForExpression;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\FunctionCall;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\FunctionLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\IfExpression;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\InExpression;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\InstanceOfExpression;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\IntervalTest;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\ListLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Name;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Negation;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Node;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\NullLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\NumberLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\PathExpression;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\QuantifiedExpression;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\RangeLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\StringLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\UnaryComparison;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\UnaryTests;

/**
 * Checks a parsed FEEL expression against the S-FEEL subset (DMN 1.5 ch. 9):
 * simple values, arithmetic, comparisons, the four date/time constructors
 * and simple unary tests. Everything else is a full-FEEL construct that a
 * model claiming S-FEEL must not use.
 *
 * @internal
 */
final class SFeelChecker
{
    private const array TEMPORAL_CONSTRUCTORS = ['date', 'time', 'date and time', 'duration'];

    private function __construct()
    {
    }

    /**
     * The full-FEEL constructs used in the AST, as human-readable construct
     * names; empty when the expression stays within S-FEEL.
     *
     * @return list<string>
     */
    public static function violations(Node $node): array
    {
        $violations = [];
        self::walk($node, $violations);

        return array_values(array_unique($violations));
    }

    /**
     * @param list<string> $violations
     */
    private static function walk(Node $node, array &$violations): void
    {
        if (
            $node instanceof NumberLiteral
            || $node instanceof StringLiteral
            || $node instanceof BooleanLiteral
            || $node instanceof Name
            || $node instanceof AnyTest
        ) {
            return;
        }

        if ($node instanceof PathExpression) {
            self::walk($node->base, $violations);

            return;
        }

        if ($node instanceof Negation) {
            self::walk($node->operand, $violations);

            return;
        }

        if ($node instanceof Binary) {
            if ($node->op === BinaryOp::And || $node->op === BinaryOp::Or) {
                $violations[] = sprintf("'%s' expressions", $node->op->value);
            }

            self::walk($node->left, $violations);
            self::walk($node->right, $violations);

            return;
        }

        if ($node instanceof FunctionCall) {
            $name = $node->calleeName();

            // The date/time constructors are S-FEEL's date time literals;
            // every other invocation is full FEEL.
            if ($name === null || !\in_array($name, self::TEMPORAL_CONSTRUCTORS, true)) {
                $violations[] = $name === null
                    ? 'function invocations'
                    : sprintf('function invocations (%s)', $name);

                return;
            }

            if ($node->namedArguments !== []) {
                $violations[] = 'named invocation arguments';
            }

            foreach ($node->arguments as $argument) {
                self::walk($argument, $violations);
            }

            return;
        }

        if ($node instanceof UnaryTests) {
            foreach ($node->tests as $test) {
                self::walk($test, $violations);
            }

            return;
        }

        if ($node instanceof ComparisonTest) {
            self::walk($node->endpoint, $violations);

            return;
        }

        if ($node instanceof IntervalTest) {
            self::walk($node->start, $violations);
            self::walk($node->end, $violations);

            return;
        }

        if ($node instanceof ExpressionTest) {
            self::walk($node->expression, $violations);

            return;
        }

        $violations[] = self::constructName($node);
    }

    private static function constructName(Node $node): string
    {
        return match (true) {
            $node instanceof IfExpression => 'if expressions',
            $node instanceof ForExpression => 'for expressions',
            $node instanceof QuantifiedExpression => 'some/every expressions',
            $node instanceof InExpression => "'in' expressions",
            $node instanceof Between => "'between' expressions",
            $node instanceof InstanceOfExpression => "'instance of' expressions",
            $node instanceof FunctionLiteral => 'function definitions',
            $node instanceof ContextLiteral => 'context literals',
            $node instanceof ListLiteral => 'list literals',
            $node instanceof Filter => 'filter expressions',
            $node instanceof RangeLiteral,
            $node instanceof ComparisonRange,
            $node instanceof UnaryComparison => 'range values',
            $node instanceof AtLiteral => 'at-literals',
            $node instanceof NullLiteral => "the 'null' literal",
            default => get_debug_type($node),
        };
    }
}
