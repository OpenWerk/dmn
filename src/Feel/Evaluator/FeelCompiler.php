<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Evaluator;

use OpenWerk\DecisionModelAndNotation\Diagnostics\Diagnostic;
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
use OpenWerk\DecisionModelAndNotation\Feel\Ast\IterationContext;
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
use OpenWerk\DecisionModelAndNotation\Feel\Ast\TypeNode;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\UnaryComparison;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\UnaryTests;
use OpenWerk\DecisionModelAndNotation\Feel\Builtins\BuiltinCatalog;
use OpenWerk\DecisionModelAndNotation\Feel\Builtins\BuiltinFunction;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ops;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ternary;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\TypeChecker;
use OpenWerk\DecisionModelAndNotation\Feel\Value\EmptyContext;
use OpenWerk\DecisionModelAndNotation\Feel\Value\EqualityTest;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDate;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelFunction;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelRange;
use OpenWerk\DecisionModelAndNotation\Feel\Value\TemporalParser;

/**
 * Compiles AST nodes into specialized closures, so repeated evaluations
 * skip node dispatch: the per-node decisions (which kind, which operator,
 * which builtin) happen once per process instead of once per evaluation.
 *
 * Compiled closures capture only compile-time constants and child
 * closures, never per-run state; diagnostics and the type resolver arrive
 * through the per-run {@see FeelEvaluator} parameter. Node kinds without a
 * dedicated compiler fall back to a closure over the interpreter, so the
 * two paths stay behaviorally identical while compilation grows per phase.
 *
 * The caches key by AST node: nodes live in parsed, immutable models, and
 * a WeakMap lets compiled code follow their lifetime.
 *
 * @phpstan-type CompiledExpr \Closure(Scope, FeelEvaluator): mixed
 * @phpstan-type CompiledContext array{string, CompiledExpr, ?CompiledExpr}
 *
 * @internal
 */
final class FeelCompiler
{
    /**
     * Pure conversion builtins whose call result is a constant when every
     * argument is a literal; their values memoize per compiled call site.
     */
    private const array CONSTANT_BUILTINS = [
        'date' => true,
        'time' => true,
        'date and time' => true,
        'duration' => true,
        'years and months duration' => true,
        'number' => true,
    ];

    /** @var \WeakMap<Node, \Closure(Scope, FeelEvaluator): mixed>|null */
    private static ?\WeakMap $expressions = null;

    /** @var \WeakMap<UnaryTests, \Closure(mixed, Scope, FeelEvaluator): ?bool>|null */
    private static ?\WeakMap $tests = null;

    private function __construct()
    {
    }

    /**
     * @return \Closure(Scope, FeelEvaluator): mixed
     */
    public static function compiled(Node $node): \Closure
    {
        $cache = self::$expressions ??= new \WeakMap();

        return $cache[$node] ??= self::compile($node);
    }

    /**
     * @return \Closure(mixed, Scope, FeelEvaluator): ?bool
     */
    public static function compiledTests(UnaryTests $tests): \Closure
    {
        $cache = self::$tests ??= new \WeakMap();

        return $cache[$tests] ??= self::compileTests($tests);
    }

    /**
     * @return \Closure(Scope, FeelEvaluator): mixed
     */
    private static function compile(Node $node): \Closure
    {
        if (
            $node instanceof NumberLiteral
            || $node instanceof StringLiteral
            || $node instanceof BooleanLiteral
        ) {
            $value = $node->value;

            return static fn(): mixed => $value;
        }

        if ($node instanceof NullLiteral) {
            return static fn(): mixed => null;
        }

        if ($node instanceof Name) {
            return self::compileName($node);
        }

        if ($node instanceof Binary) {
            return self::compileBinary($node);
        }

        if ($node instanceof InExpression) {
            return self::compileIn($node);
        }

        if ($node instanceof FunctionCall) {
            return self::compileCall($node);
        }

        if ($node instanceof FunctionLiteral) {
            return self::compileFunctionLiteral($node);
        }

        if ($node instanceof PathExpression) {
            $base = self::compiled($node->base);
            $property = $node->property;

            return static fn(Scope $scope, FeelEvaluator $rt): mixed
                => $rt->path($base($scope, $rt), $property);
        }

        if ($node instanceof IfExpression) {
            $condition = self::compiled($node->condition);
            $then = self::compiled($node->then);
            $else = self::compiled($node->else);

            return static fn(Scope $scope, FeelEvaluator $rt): mixed => $condition($scope, $rt) === true
                ? $then($scope, $rt)
                : $else($scope, $rt);
        }

        if ($node instanceof Negation) {
            $operand = self::compiled($node->operand);

            return static function (Scope $scope, FeelEvaluator $rt) use ($operand): mixed {
                try {
                    return Ops::negate($operand($scope, $rt));
                } catch (FeelError $error) {
                    return $rt->feelError($error);
                }
            };
        }

        if ($node instanceof Between) {
            $value = self::compiled($node->value);
            $lower = self::compiled($node->lower);
            $upper = self::compiled($node->upper);

            return static function (Scope $scope, FeelEvaluator $rt) use ($value, $lower, $upper): mixed {
                $operand = $value($scope, $rt);

                return Ternary::andAll(
                    $rt->compareWith(BinaryOp::Ge, $operand, $lower($scope, $rt)),
                    $rt->compareWith(BinaryOp::Le, $operand, $upper($scope, $rt)),
                );
            };
        }

        if ($node instanceof InstanceOfExpression) {
            return self::compileInstanceOf($node);
        }

        if ($node instanceof ForExpression) {
            return self::compileFor($node);
        }

        if ($node instanceof QuantifiedExpression) {
            return self::compileQuantified($node);
        }

        if ($node instanceof ListLiteral) {
            $elements = [];

            foreach ($node->elements as $element) {
                $elements[] = self::compiled($element);
            }

            return static function (Scope $scope, FeelEvaluator $rt) use ($elements): mixed {
                $values = [];

                foreach ($elements as $element) {
                    $values[] = $element($scope, $rt);
                }

                return $values;
            };
        }

        if ($node instanceof ContextLiteral) {
            return self::compileContext($node);
        }

        if ($node instanceof RangeLiteral) {
            $start = self::compiled($node->start);
            $end = self::compiled($node->end);
            $startClosed = $node->startClosed;
            $endClosed = $node->endClosed;

            return static function (
                Scope $scope,
                FeelEvaluator $rt
            ) use (
                $start,
                $end,
                $startClosed,
                $endClosed,
            ): mixed {
                try {
                    return FeelRange::of($startClosed, $start($scope, $rt), $end($scope, $rt), $endClosed);
                } catch (FeelError $error) {
                    return $rt->feelError($error);
                }
            };
        }

        if ($node instanceof ComparisonRange) {
            $operand = self::compiled($node->operand);

            return match ($node->op) {
                BinaryOp::Lt => static fn(Scope $scope, FeelEvaluator $rt): mixed
                    => new FeelRange(false, null, $operand($scope, $rt), false, startDefined: false),
                BinaryOp::Le => static fn(Scope $scope, FeelEvaluator $rt): mixed
                    => new FeelRange(false, null, $operand($scope, $rt), true, startDefined: false),
                BinaryOp::Gt => static fn(Scope $scope, FeelEvaluator $rt): mixed
                    => new FeelRange(false, $operand($scope, $rt), null, false, endDefined: false),
                default => static fn(Scope $scope, FeelEvaluator $rt): mixed
                    => new FeelRange(true, $operand($scope, $rt), null, false, endDefined: false),
            };
        }

        if ($node instanceof UnaryComparison) {
            $operand = self::compiled($node->operand);
            $negated = $node->negated;

            return static fn(Scope $scope, FeelEvaluator $rt): mixed
                => new EqualityTest($negated, $operand($scope, $rt));
        }

        if ($node instanceof Filter) {
            return self::compileFilter($node);
        }

        if ($node instanceof AtLiteral) {
            // Fold at compile time; a failing literal reports its error on
            // every evaluation, like the interpreter did.
            try {
                $value = TemporalParser::atLiteral($node->text);

                return static fn(): mixed => $value;
            } catch (FeelError $error) {
                $message = $error->getMessage();

                return static fn(Scope $scope, FeelEvaluator $rt): mixed
                    => $rt->reportError(Diagnostic::FEEL_ERROR, $message);
            }
        }

        // Unknown node kind (never produced by this parser): report per
        // evaluation, matching the interpreter's terminal branch.
        return static fn(Scope $scope, FeelEvaluator $rt): mixed => $rt->interpret($node, $scope);
    }

    /**
     * @return \Closure(Scope, FeelEvaluator): mixed
     */
    private static function compileInstanceOf(InstanceOfExpression $node): \Closure
    {
        $value = self::compiled($node->value);
        $type = $node->type;

        // A type expression naming only builtin types resolves to itself;
        // model-defined names need the per-run resolver.
        if (self::isStaticType($type)) {
            return static fn(Scope $scope, FeelEvaluator $rt): mixed
                => TypeChecker::conforms($value($scope, $rt), $type);
        }

        return static fn(Scope $scope, FeelEvaluator $rt): mixed
            => TypeChecker::conforms($value($scope, $rt), $rt->resolveType($type));
    }

    private static function isStaticType(TypeNode $type): bool
    {
        if ($type->kind === TypeNode::KIND_SIMPLE) {
            return TypeChecker::isBuiltinTypeName($type->name);
        }

        foreach ($type->parameters as $parameter) {
            if (!self::isStaticType($parameter)) {
                return false;
            }
        }

        foreach ($type->members as $member) {
            if (!self::isStaticType($member)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return \Closure(Scope, FeelEvaluator): mixed
     */
    private static function compileFor(ForExpression $node): \Closure
    {
        $contexts = self::compileIterationContexts($node->contexts);
        $return = self::compiled($node->return);

        return static function (Scope $scope, FeelEvaluator $rt) use ($contexts, $return): mixed {
            $results = [];
            $body = static function (Scope $iterationScope) use (&$results, $return, $rt): void {
                // `partial` exposes the results produced so far (spec 10.3.2.14).
                $results[] = $return($iterationScope->child(['partial' => $results]), $rt);
            };

            return self::iterate($contexts, 0, $scope, $rt, $body) ? $results : null;
        };
    }

    /**
     * @return \Closure(Scope, FeelEvaluator): mixed
     */
    private static function compileQuantified(QuantifiedExpression $node): \Closure
    {
        $contexts = self::compileIterationContexts($node->contexts);
        $satisfies = self::compiled($node->satisfies);
        $every = $node->every;

        return static function (Scope $scope, FeelEvaluator $rt) use ($contexts, $satisfies, $every): mixed {
            $outcomes = [];
            $collect = static function (Scope $iterationScope) use (&$outcomes, $satisfies, $rt): void {
                $outcome = $satisfies($iterationScope, $rt);
                $outcomes[] = \is_bool($outcome) ? $outcome : null;
            };

            if (!self::iterate($contexts, 0, $scope, $rt, $collect)) {
                return null;
            }

            return $every ? Ternary::andAll(...$outcomes) : Ternary::orAll(...$outcomes);
        };
    }

    /**
     * @param list<IterationContext> $iterationContexts
     *
     * @return list<CompiledContext>
     */
    private static function compileIterationContexts(array $iterationContexts): array
    {
        $contexts = [];

        foreach ($iterationContexts as $context) {
            $contexts[] = [
                $context->name,
                self::compiled($context->domain),
                $context->domainEnd === null ? null : self::compiled($context->domainEnd),
            ];
        }

        return $contexts;
    }

    /**
     * Runs $body for every combination of the iteration contexts; false
     * signals a domain error (already reported).
     *
     * @param list<CompiledContext> $contexts
     * @param \Closure(Scope): void $body
     */
    private static function iterate(
        array $contexts,
        int $index,
        Scope $scope,
        FeelEvaluator $rt,
        \Closure $body,
    ): bool {
        if ($index >= \count($contexts)) {
            $body($scope);

            return true;
        }

        [$name, $domain, $domainEnd] = $contexts[$index];
        $values = self::domainValues($domain, $domainEnd, $scope, $rt);

        if ($values === null) {
            return false;
        }

        foreach ($values as $value) {
            if (!self::iterate($contexts, $index + 1, $scope->child([$name => $value]), $rt, $body)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param \Closure(Scope, FeelEvaluator): mixed  $domain
     * @param ?\Closure(Scope, FeelEvaluator): mixed $domainEnd
     *
     * @return list<mixed>|null
     */
    private static function domainValues(
        \Closure $domain,
        ?\Closure $domainEnd,
        Scope $scope,
        FeelEvaluator $rt,
    ): ?array {
        $start = $domain($scope, $rt);

        if ($domainEnd !== null) {
            $end = $domainEnd($scope, $rt);

            if ($start instanceof FeelDate && $end instanceof FeelDate) {
                return self::sequence(
                    $start->epochDay(),
                    $end->epochDay(),
                    static fn(int $day): mixed => FeelDate::fromEpochDay($day),
                );
            }

            if (!$start instanceof FeelNumber || !$end instanceof FeelNumber) {
                $rt->reportError(Diagnostic::FEEL_ERROR, 'iteration bounds must both be numbers or both be dates');

                return null;
            }

            try {
                $from = $start->toInt();
                $to = $end->toInt();
            } catch (FeelError $error) {
                $rt->feelError($error);

                return null;
            }

            return self::sequence($from, $to, static fn(int $i): mixed => FeelNumber::of($i));
        }

        if ($start === null) {
            $rt->reportError(Diagnostic::FEEL_ERROR, 'iteration domain is null');

            return null;
        }

        if ($start instanceof FeelRange) {
            $rt->reportError(
                Diagnostic::FEEL_ERROR,
                'cannot iterate over a range value; use start..end iteration bounds',
            );

            return null;
        }

        if (Ops::isList($start)) {
            \assert(\is_array($start));

            return array_values($start);
        }

        return [$start];
    }

    /**
     * @param \Closure(int): mixed $make
     *
     * @return list<mixed>
     */
    private static function sequence(int $from, int $to, \Closure $make): array
    {
        $values = [];
        $step = $from <= $to ? 1 : -1;

        for ($i = $from; $step > 0 ? $i <= $to : $i >= $to; $i += $step) {
            $values[] = $make($i);
        }

        return $values;
    }

    /**
     * @return \Closure(Scope, FeelEvaluator): mixed
     */
    private static function compileContext(ContextLiteral $node): \Closure
    {
        $entries = [];

        foreach ($node->entries as [$key, $expression]) {
            $entries[] = [$key, self::compiled($expression)];
        }

        return static function (Scope $scope, FeelEvaluator $rt) use ($entries): mixed {
            $context = [];

            foreach ($entries as [$key, $closure]) {
                if (\array_key_exists($key, $context)) {
                    return $rt->reportError(
                        Diagnostic::FEEL_ERROR,
                        sprintf('duplicate context key %s', var_export($key, true)),
                    );
                }

                // Later entries see the earlier ones in scope.
                $context[$key] = $closure($scope->child($context), $rt);
            }

            return $context === [] ? EmptyContext::instance() : $context;
        };
    }

    /**
     * @return \Closure(Scope, FeelEvaluator): mixed
     */
    private static function compileFilter(Filter $node): \Closure
    {
        $base = self::compiled($node->base);
        $condition = self::compiled($node->condition);

        return static function (Scope $scope, FeelEvaluator $rt) use ($base, $condition): mixed {
            $baseValue = $base($scope, $rt);

            if ($baseValue === null) {
                return null;
            }

            $list = Ops::isList($baseValue) ? array_values((array) $baseValue) : [$baseValue];

            // A numeric filter (evaluable in the outer scope) selects by
            // index; the probe runs muted, its findings are never reported.
            $probe = $condition($scope, $rt->probeRuntime());

            if ($probe instanceof FeelNumber) {
                try {
                    $index = $probe->isInteger() ? $probe->toInt() : null;
                } catch (FeelError) {
                    $index = null;
                }

                if ($index === null || $index === 0) {
                    return $rt->reportError(Diagnostic::FEEL_ERROR, 'filter index must be a non-zero integer');
                }

                $offset = $index > 0 ? $index - 1 : \count($list) + $index;

                return $list[$offset] ?? null;
            }

            $selected = [];

            foreach ($list as $item) {
                $bindings = [];

                if (Ops::isContext($item)) {
                    foreach (Ops::contextToArray($item) as $key => $member) {
                        $bindings[(string) $key] = $member;
                    }
                }

                // The whole element is reachable as `item` — unless the element
                // itself defines an `item` member, which takes precedence.
                if (!\array_key_exists('item', $bindings)) {
                    $bindings['item'] = $item;
                }

                if ($condition($scope->child($bindings), $rt) === true) {
                    $selected[] = $item;
                }
            }

            return $selected;
        };
    }

    /**
     * @return \Closure(Scope, FeelEvaluator): mixed
     */
    private static function compileCall(FunctionCall $node): \Closure
    {
        $argClosures = [];

        foreach ($node->arguments as $argument) {
            $argClosures[] = self::compiled($argument);
        }

        $namedClosures = [];

        foreach ($node->namedArguments as $parameter => $argument) {
            $namedClosures[$parameter] = self::compiled($argument);
        }

        $calleeName = $node->calleeName();

        if ($calleeName === null) {
            $callee = self::compiled($node->callee);

            return static function (
                Scope $scope,
                FeelEvaluator $rt
            ) use (
                $argClosures,
                $namedClosures,
                $callee,
            ): mixed {
                $positional = [];

                foreach ($argClosures as $closure) {
                    $positional[] = $closure($scope, $rt);
                }

                $named = [];

                foreach ($namedClosures as $parameter => $closure) {
                    $named[$parameter] = $closure($scope, $rt);
                }

                $target = $callee($scope, $rt);

                if ($target === null) {
                    return null;
                }

                if (!$target instanceof FeelFunction) {
                    return $rt->reportError(
                        Diagnostic::FEEL_ERROR,
                        sprintf('cannot invoke a %s', Ops::typeName($target)),
                    );
                }

                return $rt->invokeFunction('anonymous function', $target, $positional, $named);
            };
        }

        // The builtin, its signature binding and the constant eligibility
        // are all argument-value independent: resolved once at compile time.
        $builtin = BuiltinCatalog::standard()->get($calleeName);
        $plan = $builtin === null
            ? null
            : self::bindingPlan($builtin, \count($node->arguments), array_keys($node->namedArguments));
        $constant = $builtin !== null
            && isset(self::CONSTANT_BUILTINS[$calleeName])
            && $node->namedArguments === []
            && self::allLiterals($node->arguments);
        $signatureError = sprintf('%s(): arguments do not match any signature', $calleeName);
        $shadowName = var_export($calleeName, true);
        $unknownMessage = sprintf('unknown function %s', var_export($calleeName, true));
        $memo = null;

        return static function (
            Scope $scope,
            FeelEvaluator $rt
        ) use (
            $argClosures,
            $namedClosures,
            $calleeName,
            $builtin,
            $plan,
            $constant,
            $signatureError,
            $shadowName,
            $unknownMessage,
            &$memo
        ): mixed {
            $hasShadow = $scope->has($calleeName);

            // A memoized constant call short-circuits; its arguments are
            // literals, so skipping their evaluation is unobservable.
            if ($constant && $memo !== null && !$hasShadow) {
                return $memo;
            }

            $positional = [];

            foreach ($argClosures as $closure) {
                $positional[] = $closure($scope, $rt);
            }

            $named = [];

            foreach ($namedClosures as $parameter => $closure) {
                $named[$parameter] = $closure($scope, $rt);
            }

            $shadowValue = null;

            if ($hasShadow) {
                $shadowValue = $scope->get($calleeName);

                if ($shadowValue instanceof FeelFunction) {
                    return $rt->invokeFunction($calleeName, $shadowValue, $positional, $named);
                }
            }

            if ($builtin !== null) {
                if ($plan === null) {
                    return $rt->reportError(Diagnostic::FEEL_ERROR, $signatureError);
                }

                [$slots, $provided, $signatureIndex, $padNulls] = $plan;

                if ($slots === null) {
                    $arguments = $padNulls === [] ? $positional : [...$positional, ...$padNulls];
                } else {
                    $arguments = [];

                    foreach ($slots as $slot) {
                        $arguments[] = $slot === null ? null : ($named[$slot] ?? null);
                    }
                }

                try {
                    $value = $builtin->invoke($arguments, $provided, $signatureIndex);
                } catch (FeelError $error) {
                    return $rt->feelError($error);
                }

                if ($constant && $value !== null) {
                    $memo = $value;
                }

                return $value;
            }

            if ($hasShadow) {
                return $rt->reportError(
                    Diagnostic::FEEL_ERROR,
                    sprintf('%s is a %s, not a function', $shadowName, Ops::typeName($shadowValue)),
                );
            }

            return $rt->reportError(Diagnostic::FEEL_UNKNOWN_FUNCTION, $unknownMessage);
        };
    }

    /**
     * Precomputes what {@see BuiltinFunction::bind()} would decide for this
     * call site: binding depends only on the positional argument count and
     * the named argument keys, both fixed at compile time.
     *
     * The plan is [named slots or null for positional, provided flags,
     * named signature index, null padding for omitted optionals]; null when
     * no signature matches (the call always fails).
     *
     * @param list<string> $namedKeys
     *
     * @return array{?list<?string>, list<bool>, int, list<null>}|null
     */
    private static function bindingPlan(BuiltinFunction $builtin, int $positionalCount, array $namedKeys): ?array
    {
        if ($namedKeys !== []) {
            if ($positionalCount > 0) {
                return null; // FEEL forbids mixing positional and named arguments
            }

            foreach ($builtin->signatures as $signatureIndex => [$parameters, $required]) {
                if (array_diff($namedKeys, $parameters) !== []) {
                    continue;
                }

                $slots = [];
                $provided = [];
                $satisfied = true;

                foreach ($parameters as $index => $parameter) {
                    if (\in_array($parameter, $namedKeys, true)) {
                        $slots[] = $parameter;
                        $provided[] = true;
                    } elseif ($index < $required) {
                        $satisfied = false;
                        break;
                    } else {
                        $slots[] = null;
                        $provided[] = false;
                    }
                }

                if ($satisfied) {
                    return [$slots, $provided, $signatureIndex, []];
                }
            }

            return null;
        }

        if ($builtin->variadic) {
            return [null, array_fill(0, $positionalCount, true), -1, []];
        }

        foreach ($builtin->signatures as [$parameters, $required]) {
            if ($positionalCount >= $required && $positionalCount <= \count($parameters)) {
                $padding = \count($parameters) - $positionalCount;

                return [
                    null,
                    [...array_fill(0, $positionalCount, true), ...array_fill(0, $padding, false)],
                    -1,
                    array_fill(0, $padding, null),
                ];
            }
        }

        return null;
    }

    /**
     * @return \Closure(Scope, FeelEvaluator): mixed
     */
    private static function compileFunctionLiteral(FunctionLiteral $node): \Closure
    {
        if ($node->external || $node->body === null) {
            return static function (Scope $scope, FeelEvaluator $rt): mixed {
                return $rt->reportWarning(
                    Diagnostic::FEEL_ERROR,
                    'external functions are not supported (documented deviation); the function is null',
                );
            };
        }

        $body = self::compiled($node->body);
        $parameters = $node->parameters;
        $parameterTypes = $node->parameterTypes;

        // The function value closes over the evaluation-time scope and run,
        // exactly like the interpreted form did: it is a runtime value, not
        // a compile-time one.
        return static fn(Scope $scope, FeelEvaluator $rt): FeelFunction => new FeelFunction(
            $parameters,
            static function (array $arguments) use ($body, $parameters, $parameterTypes, $scope, $rt): mixed {
                $bindings = [];

                foreach ($parameters as $index => $parameter) {
                    $argument = $arguments[$index] ?? null;
                    $type = $parameterTypes[$index] ?? null;

                    if ($type !== null && $argument !== null) {
                        if (!TypeChecker::conforms($argument, $rt->resolveType($type))) {
                            throw new FeelError(sprintf(
                                'argument %s does not conform to its declared type',
                                var_export($parameter, true),
                            ));
                        }
                    }

                    $bindings[$parameter] = $argument;
                }

                return $body($scope->child($bindings), $rt);
            },
        );
    }

    /**
     * @param list<Node> $nodes
     */
    private static function allLiterals(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (
                !$node instanceof StringLiteral
                && !$node instanceof NumberLiteral
                && !$node instanceof BooleanLiteral
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return \Closure(Scope, FeelEvaluator): mixed
     */
    private static function compileName(Name $node): \Closure
    {
        $name = $node->name;

        // A dotted name ("Person.Gender") merged by the registry may really
        // be a path in the scope at hand: the candidate prefixes and their
        // remaining path segments are fixed, so precompute them (longest
        // prefix first).
        $prefixPaths = [];

        if (str_contains($name, '.')) {
            $segments = explode('.', $name);

            for ($take = \count($segments) - 1; $take >= 1; $take--) {
                $prefixPaths[] = [
                    implode('.', \array_slice($segments, 0, $take)),
                    \array_slice($segments, $take),
                ];
            }
        }

        // A bare builtin name ("abs") is a first-class function value unless
        // something in scope shadows it; the wrapper is a constant.
        $builtin = BuiltinCatalog::standard()->get($name);
        $builtinValue = $builtin === null ? null : FeelEvaluator::builtinFunction($builtin);
        $unknownMessage = sprintf('unknown name %s', var_export($name, true));

        return static function (
            Scope $scope,
            FeelEvaluator $rt
        ) use (
            $name,
            $prefixPaths,
            $builtinValue,
            $unknownMessage,
        ): mixed {
            if ($scope->has($name)) {
                return $scope->get($name);
            }

            foreach ($prefixPaths as [$prefix, $properties]) {
                if (!$scope->has($prefix)) {
                    continue;
                }

                $value = $scope->get($prefix);

                foreach ($properties as $property) {
                    $value = $rt->path($value, $property);
                }

                return $value;
            }

            if ($builtinValue !== null) {
                return $builtinValue;
            }

            return $rt->reportError(Diagnostic::FEEL_UNKNOWN_NAME, $unknownMessage);
        };
    }

    /**
     * @return \Closure(Scope, FeelEvaluator): mixed
     */
    private static function compileBinary(Binary $node): \Closure
    {
        $left = self::compiled($node->left);
        $right = self::compiled($node->right);
        $op = $node->op;

        if ($op === BinaryOp::And) {
            return static function (Scope $scope, FeelEvaluator $rt) use ($left, $right): mixed {
                $l = $left($scope, $rt);
                $l = \is_bool($l) ? $l : null;

                if ($l === false) {
                    return false;
                }

                $r = $right($scope, $rt);

                return Ternary::andAll($l, \is_bool($r) ? $r : null);
            };
        }

        if ($op === BinaryOp::Or) {
            return static function (Scope $scope, FeelEvaluator $rt) use ($left, $right): mixed {
                $l = $left($scope, $rt);
                $l = \is_bool($l) ? $l : null;

                if ($l === true) {
                    return true;
                }

                $r = $right($scope, $rt);

                return Ternary::orAll($l, \is_bool($r) ? $r : null);
            };
        }

        if ($op === BinaryOp::Eq) {
            return static function (Scope $scope, FeelEvaluator $rt) use ($left, $right): mixed {
                $l = $left($scope, $rt);
                $r = $right($scope, $rt);

                return Ops::equals($l, $r);
            };
        }

        if ($op === BinaryOp::Ne) {
            return static function (Scope $scope, FeelEvaluator $rt) use ($left, $right): mixed {
                $l = $left($scope, $rt);
                $r = $right($scope, $rt);

                return Ternary::negate(Ops::equals($l, $r));
            };
        }

        if (
            $op === BinaryOp::Lt || $op === BinaryOp::Le
            || $op === BinaryOp::Gt || $op === BinaryOp::Ge
        ) {
            return static function (Scope $scope, FeelEvaluator $rt) use ($left, $right, $op): mixed {
                $l = $left($scope, $rt);
                $r = $right($scope, $rt);

                return $rt->compareWith($op, $l, $r);
            };
        }

        $operation = match ($op) {
            BinaryOp::Add => Ops::add(...),
            BinaryOp::Subtract => Ops::subtract(...),
            BinaryOp::Multiply => Ops::multiply(...),
            BinaryOp::Divide => Ops::divide(...),
            BinaryOp::Power => Ops::power(...),
        };

        return static function (Scope $scope, FeelEvaluator $rt) use ($left, $right, $operation): mixed {
            $l = $left($scope, $rt);
            $r = $right($scope, $rt);

            try {
                return $operation($l, $r);
            } catch (FeelError $error) {
                return $rt->feelError($error);
            }
        };
    }

    /**
     * @return \Closure(Scope, FeelEvaluator): mixed
     */
    private static function compileIn(InExpression $node): \Closure
    {
        $value = self::compiled($node->value);
        $tests = [];

        foreach ($node->tests as $test) {
            $tests[] = self::compileSingleTest($test);
        }

        return static function (Scope $scope, FeelEvaluator $rt) use ($value, $tests): mixed {
            $input = $value($scope, $rt);
            $bound = $scope->child(['?' => $input]);
            $results = [];

            foreach ($tests as $test) {
                $results[] = $test($input, $bound, $rt);
            }

            return Ternary::orAll(...$results);
        };
    }

    /**
     * @return \Closure(mixed, Scope, FeelEvaluator): ?bool
     */
    private static function compileTests(UnaryTests $unaryTests): \Closure
    {
        $tests = [];

        foreach ($unaryTests->tests as $test) {
            $tests[] = self::compileSingleTest($test);
        }

        // All tests evaluate (no short-circuit), matching the interpreter's
        // diagnostic behavior; the disjunction is ternary.
        if (!$unaryTests->negated) {
            return static function (mixed $input, Scope $boundScope, FeelEvaluator $rt) use ($tests): ?bool {
                $results = [];

                foreach ($tests as $test) {
                    $results[] = $test($input, $boundScope, $rt);
                }

                return Ternary::orAll(...$results);
            };
        }

        return static function (mixed $input, Scope $boundScope, FeelEvaluator $rt) use ($tests): ?bool {
            $results = [];

            foreach ($tests as $test) {
                $results[] = $test($input, $boundScope, $rt);
            }

            return Ternary::negate(Ternary::orAll(...$results));
        };
    }

    /**
     * @return \Closure(mixed, Scope, FeelEvaluator): ?bool
     */
    private static function compileSingleTest(Node $test): \Closure
    {
        if ($test instanceof AnyTest) {
            return static fn(): bool => true;
        }

        if ($test instanceof ComparisonTest) {
            $endpoint = self::compiled($test->endpoint);
            $op = $test->op;

            if ($op === BinaryOp::Eq) {
                return static fn(mixed $input, Scope $scope, FeelEvaluator $rt): ?bool
                    => Ops::equals($input, $endpoint($scope, $rt));
            }

            if ($op === BinaryOp::Ne) {
                return static fn(mixed $input, Scope $scope, FeelEvaluator $rt): ?bool
                    => Ternary::negate(Ops::equals($input, $endpoint($scope, $rt)));
            }

            return static fn(mixed $input, Scope $scope, FeelEvaluator $rt): ?bool
                => $rt->compareWith($op, $input, $endpoint($scope, $rt));
        }

        if ($test instanceof IntervalTest) {
            $start = self::compiled($test->start);
            $end = self::compiled($test->end);
            $startOp = $test->startClosed ? BinaryOp::Ge : BinaryOp::Gt;
            $endOp = $test->endClosed ? BinaryOp::Le : BinaryOp::Lt;

            return static fn(mixed $input, Scope $scope, FeelEvaluator $rt): ?bool => Ternary::andAll(
                $rt->compareWith($startOp, $input, $start($scope, $rt)),
                $rt->compareWith($endOp, $input, $end($scope, $rt)),
            );
        }

        if ($test instanceof ExpressionTest) {
            $expression = self::compiled($test->expression);
            $isFunctionCall = $test->expression instanceof FunctionCall;

            return static function (
                mixed $input,
                Scope $scope,
                FeelEvaluator $rt
            ) use (
                $expression,
                $isFunctionCall,
            ): ?bool {
                $value = $expression($scope, $rt);

                if ($value instanceof FeelRange) {
                    return $value->includes($input);
                }

                if (Ops::isList($value)) {
                    \assert(\is_array($value));
                    $results = [];

                    foreach ($value as $item) {
                        // A range item tests inclusion; other items test list
                        // membership, where a cross-kind mismatch simply means
                        // "not a member" (false, never null).
                        $results[] = $item instanceof FeelRange
                            ? $item->includes($input)
                            : Ops::equals($input, $item) === true;
                    }

                    return Ternary::orAll(...$results);
                }

                if (\is_bool($value) && $isFunctionCall) {
                    // A boolean-valued invocation such as `starts with(?, "A")`
                    // is itself the test result.
                    return $value;
                }

                return Ops::equals($input, $value);
            };
        }

        $message = sprintf('unsupported unary test node %s', get_debug_type($test));

        return static function (mixed $input, Scope $scope, FeelEvaluator $rt) use ($message): ?bool {
            $rt->reportError(Diagnostic::FEEL_ERROR, $message);

            return null;
        };
    }
}
