<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Evaluator;

use OpenWerk\DecisionModelAndNotation\Diagnostics\Diagnostic;
use OpenWerk\DecisionModelAndNotation\Diagnostics\DiagnosticCollector;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\BinaryOp;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Node;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\TypeNode;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\UnaryTests;
use OpenWerk\DecisionModelAndNotation\Feel\Builtins\BuiltinFunction;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ops;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Properties;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\TypeChecker;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelFunction;

/**
 * Tree-walking evaluator for FEEL expressions and unary tests.
 *
 * Errors never throw through this class: per the DMN specification an
 * erroneous expression evaluates to null, and the reason is recorded on the
 * diagnostic collector.
 *
 * @internal
 */
final class FeelEvaluator
{
    /** Muted evaluator for probe evaluations (numeric filter detection). */
    private ?self $probe = null;

    /**
     * @param \Closure(string): ?TypeNode $typeResolver resolves model-defined type names for `instance of`
     */
    public function __construct(
        private readonly DiagnosticCollector $diagnostics,
        private readonly ?\Closure $typeResolver = null,
    ) {
    }

    public function evaluate(Node $node, Scope $scope): mixed
    {
        return FeelCompiler::compiled($node)($scope, $this);
    }

    /**
     * Terminal fallback for node kinds without a compiler: every kind this
     * parser produces compiles, so reaching this reports the unsupported
     * node, once per evaluation, exactly like the interpreter chain's final
     * branch did.
     */
    public function interpret(Node $node, Scope $scope): mixed
    {
        $this->diagnostics->error(
            Diagnostic::FEEL_ERROR,
            sprintf('unsupported expression node %s', get_debug_type($node)),
        );

        return null;
    }

    /**
     * The muted evaluator numeric-filter probes run under: findings are
     * dropped, one instance per run.
     */
    public function probeRuntime(): self
    {
        return $this->probe ??= new self(DiagnosticCollector::muted());
    }

    /**
     * Evaluates a decision-table input entry against an input value.
     */
    public function test(UnaryTests $tests, mixed $input, Scope $scope): ?bool
    {
        return FeelCompiler::compiledTests($tests)($input, $scope->child(['?' => $input]), $this);
    }

    /**
     * Like {@see test()}, with the input already bound as `?` in the given
     * scope. Decision table matching tests one input value against every
     * rule of a column and reuses one bound scope for the whole column.
     */
    public function testBound(UnaryTests $tests, mixed $input, Scope $boundScope): ?bool
    {
        return FeelCompiler::compiledTests($tests)($input, $boundScope, $this);
    }

    public function compareWith(BinaryOp $op, mixed $a, mixed $b): ?bool
    {
        try {
            $comparison = Ops::compare($a, $b);
        } catch (FeelError $error) {
            $this->feelError($error);

            return null;
        }

        if ($comparison === null) {
            return null;
        }

        return match ($op) {
            BinaryOp::Lt => $comparison < 0,
            BinaryOp::Le => $comparison <= 0,
            BinaryOp::Gt => $comparison > 0,
            BinaryOp::Ge => $comparison >= 0,
            default => null,
        };
    }

    /**
     * @param list<mixed>          $positional
     * @param array<string, mixed> $named
     */
    public function invokeFunction(string $name, FeelFunction $function, array $positional, array $named): mixed
    {
        if ($named !== []) {
            if (array_diff(array_keys($named), $function->parameterNames) !== []) {
                $this->diagnostics->error(
                    Diagnostic::FEEL_ERROR,
                    sprintf('%s(): unknown named parameters', $name),
                );

                return null;
            }

            $positional = [];

            foreach ($function->parameterNames as $parameter) {
                $positional[] = $named[$parameter] ?? null;
            }
        } elseif (\count($positional) !== \count($function->parameterNames)) {
            $this->diagnostics->error(
                Diagnostic::FEEL_ERROR,
                sprintf(
                    '%s() expects %d arguments, %d given',
                    $name,
                    \count($function->parameterNames),
                    \count($positional),
                ),
            );

            return null;
        }

        try {
            return $function->invoke($positional);
        } catch (FeelError $error) {
            return $this->feelError($error);
        }
    }

    /**
     * Wraps a builtin as a first-class FEEL function value, so a bare name
     * like `abs` can be passed as an argument. Parameter names follow the
     * builtin's first signature.
     */
    public static function builtinFunction(BuiltinFunction $builtin): FeelFunction
    {
        return new FeelFunction(
            $builtin->signatures[0][0],
            static function (array $arguments) use ($builtin): mixed {
                $bound = $builtin->bind($arguments, []);

                if ($bound === null) {
                    throw new FeelError(sprintf('%s(): arguments do not match any signature', $builtin->name));
                }

                return $builtin->invoke(...$bound);
            },
        );
    }

    public function path(mixed $base, string $property): mixed
    {
        if ($base === null) {
            return null;
        }

        if (Ops::isContext($base)) {
            $members = Ops::contextToArray($base);

            return \array_key_exists($property, $members) ? $members[$property] : null;
        }

        if (Ops::isList($base)) {
            \assert(\is_array($base));
            $projected = [];

            foreach ($base as $element) {
                $projected[] = $this->path($element, $property);
            }

            return $projected;
        }

        try {
            return Properties::of($base, $property);
        } catch (FeelError $error) {
            return $this->feelError($error);
        }
    }

    /**
     * Replaces model-defined type names (item definitions) in a type
     * expression with their resolved structure.
     */
    public function resolveType(TypeNode $type): TypeNode
    {
        if ($type->kind === TypeNode::KIND_SIMPLE) {
            if ($this->typeResolver === null || TypeChecker::isBuiltinTypeName($type->name)) {
                return $type;
            }

            return ($this->typeResolver)($type->name) ?? $type;
        }

        $parameters = [];

        foreach ($type->parameters as $parameter) {
            $parameters[] = $this->resolveType($parameter);
        }

        $members = [];

        foreach ($type->members as $member => $memberType) {
            $members[$member] = $this->resolveType($memberType);
        }

        return new TypeNode($type->kind, $type->name, $parameters, $members);
    }

    public function feelError(FeelError $error): mixed
    {
        $this->diagnostics->error(Diagnostic::FEEL_ERROR, $error->getMessage());

        return null;
    }

    /**
     * Records an error diagnostic and yields the FEEL error value (null),
     * so compiled closures can report and return in one expression.
     */
    public function reportError(string $code, string $message): mixed
    {
        $this->diagnostics->error($code, $message);

        return null;
    }

    /**
     * Records a warning diagnostic and yields null, the warning-severity
     * counterpart of {@see reportError()}.
     */
    public function reportWarning(string $code, string $message): mixed
    {
        $this->diagnostics->warning($code, $message);

        return null;
    }
}
