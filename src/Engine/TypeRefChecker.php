<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Engine;

use OpenWerk\DecisionModelAndNotation\Diagnostics\DiagnosticCollector;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\TypeNode;
use OpenWerk\DecisionModelAndNotation\Feel\Evaluator\FeelEvaluator;
use OpenWerk\DecisionModelAndNotation\Feel\Evaluator\Scope;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ops;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\TypeChecker;
use OpenWerk\DecisionModelAndNotation\Model\Definitions;
use OpenWerk\DecisionModelAndNotation\Model\ItemDefinition;

/**
 * Checks values against declared typeRefs (decision variables, BKM formal
 * parameters), resolving item definitions and applying FEEL's implicit
 * conversions: a singleton list converts to its element and vice versa.
 *
 * Item definition `allowedValues` are enforced as part of conformance: a
 * value outside the allowed values does not conform to the declared type.
 *
 * @internal
 */
final class TypeRefChecker
{
    private const array SIMPLE_TYPES = [
        'number',
        'string',
        'boolean',
        'date',
        'time',
        'date and time',
        'dateTime',
        'days and time duration',
        'dayTimeDuration',
        'years and months duration',
        'yearMonthDuration',
        'list',
        'context',
        'range',
        'function',
        'null',
    ];

    private ?FeelEvaluator $constraintEvaluator = null;

    /** @var array<string, self> checkers for imported models, keyed by import name */
    private array $importedCheckers = [];

    public function __construct(private readonly Definitions $definitions)
    {
    }

    /**
     * The (memoized) checker over an imported model, so type resolutions
     * that chain through imports reuse one checker per import name.
     */
    private function importedChecker(string $importName): ?self
    {
        if (\array_key_exists($importName, $this->importedCheckers)) {
            return $this->importedCheckers[$importName];
        }

        $imported = $this->definitions->importedModel($importName);

        if ($imported === null) {
            return null;
        }

        return $this->importedCheckers[$importName] = new self($imported);
    }

    /**
     * Checks and coerces a value against a typeRef.
     *
     * @return array{mixed, bool} [possibly-coerced value, conforms]
     */
    public function coerce(mixed $value, ?string $typeRef): array
    {
        $type = $this->resolve($typeRef, 0);

        if ($value === null) {
            return [$value, true];
        }

        if ($type === null) {
            return $this->satisfiesConstraints($value, $typeRef, 0) ? [$value, true] : [null, false];
        }

        if (TypeChecker::conforms($value, $type, lenientNulls: true)) {
            return $this->satisfiesConstraints($value, $typeRef, 0) ? [$value, true] : [null, false];
        }

        // singleton list -> element
        if (Ops::isList($value) && \is_array($value) && \count($value) === 1) {
            if (TypeChecker::conforms($value[0], $type, lenientNulls: true)) {
                return $this->satisfiesConstraints($value[0], $typeRef, 0) ? [$value[0], true] : [null, false];
            }
        }

        // element -> singleton list
        if ($type->kind === TypeNode::KIND_LIST && $type->parameters !== []) {
            if (TypeChecker::conforms($value, $type->parameters[0], lenientNulls: true)) {
                return $this->satisfiesConstraints([$value], $typeRef, 0) ? [[$value], true] : [null, false];
            }
        }

        return [null, false];
    }

    /**
     * Resolves a model-defined type name for `instance of` evaluation.
     */
    public function resolveName(string $name): ?TypeNode
    {
        return $this->resolve($name, 0);
    }

    /**
     * The output typeRef of a `<functionItem>` item definition, when the
     * given typeRef names one (function-typed variables of BKMs and
     * decision services).
     */
    public function functionOutputTypeRef(?string $typeRef): ?string
    {
        if ($typeRef === null) {
            return null;
        }

        return $this->definitions->itemDefinition(trim($typeRef))?->functionOutputTypeRef;
    }

    /**
     * Resolves a typeRef to a type node; null means "no checkable type"
     * (unknown or Any).
     */
    private function resolve(?string $typeRef, int $depth): ?TypeNode
    {
        if ($typeRef === null || $typeRef === '' || $typeRef === 'Any' || $depth > 32) {
            return null;
        }

        $typeRef = trim($typeRef);

        if (\in_array($typeRef, self::SIMPLE_TYPES, true)) {
            return new TypeNode(TypeNode::KIND_SIMPLE, self::canonicalName($typeRef));
        }

        $itemDefinition = $this->definitions->itemDefinition($typeRef);

        if ($itemDefinition !== null) {
            return $this->fromItemDefinition($itemDefinition, $depth + 1);
        }

        // Import-qualified type ("myimport.tPerson"): resolve the remainder
        // against the imported model's own item definitions.
        $dot = strpos($typeRef, '.');

        while ($dot !== false) {
            $imported = $this->importedChecker(substr($typeRef, 0, $dot));

            if ($imported !== null) {
                return $imported->resolve(substr($typeRef, $dot + 1), $depth + 1);
            }

            $dot = strpos($typeRef, '.', $dot + 1);
        }

        return null;
    }

    private function fromItemDefinition(ItemDefinition $definition, int $depth): ?TypeNode
    {
        if ($depth > 32) {
            return null;
        }

        $type = null;

        if ($definition->components !== []) {
            $members = [];

            foreach ($definition->components as $component) {
                $memberType = $component->components !== []
                    ? $this->fromItemDefinition($component, $depth + 1)
                    : $this->resolve($component->typeRef, $depth + 1);

                if ($memberType !== null) {
                    $members[$component->name] = $component->isCollection
                        ? new TypeNode(TypeNode::KIND_LIST, 'list', [$memberType])
                        : $memberType;
                }
            }

            $type = new TypeNode(TypeNode::KIND_CONTEXT, 'context', [], $members);
        } elseif ($definition->typeRef !== null) {
            $type = $this->resolve($definition->typeRef, $depth + 1);
        }

        if ($definition->isCollection) {
            // An Any-typed collection still constrains to "a list".
            return new TypeNode(TypeNode::KIND_LIST, 'list', $type === null ? [] : [$type]);
        }

        return $type;
    }

    private static function canonicalName(string $name): string
    {
        return match ($name) {
            'dateTime' => 'date and time',
            'dayTimeDuration' => 'days and time duration',
            'yearMonthDuration' => 'years and months duration',
            default => $name,
        };
    }

    /**
     * Whether the value satisfies every `allowedValues` constraint declared
     * along the typeRef's item definition chain, including components and
     * collection elements.
     */
    private function satisfiesConstraints(mixed $value, ?string $typeRef, int $depth): bool
    {
        if ($value === null || $typeRef === null || $depth > 32) {
            return true;
        }

        $typeRef = trim($typeRef);
        $itemDefinition = $this->definitions->itemDefinition($typeRef);

        if ($itemDefinition !== null) {
            return $this->definitionConstraints($value, $itemDefinition, $depth);
        }

        // Import-qualified type: check against the imported model.
        $dot = strpos($typeRef, '.');

        while ($dot !== false) {
            $imported = $this->importedChecker(substr($typeRef, 0, $dot));

            if ($imported !== null) {
                return $imported->satisfiesConstraints($value, substr($typeRef, $dot + 1), $depth + 1);
            }

            $dot = strpos($typeRef, '.', $dot + 1);
        }

        return true;
    }

    private function definitionConstraints(mixed $value, ItemDefinition $definition, int $depth): bool
    {
        if ($value === null || $depth > 32) {
            return true;
        }

        if ($definition->isCollection) {
            if (!Ops::isList($value)) {
                return true;
            }

            \assert(\is_array($value));

            foreach ($value as $element) {
                if (!$this->elementConstraints($element, $definition, $depth)) {
                    return false;
                }
            }

            return true;
        }

        return $this->elementConstraints($value, $definition, $depth);
    }

    private function elementConstraints(mixed $value, ItemDefinition $definition, int $depth): bool
    {
        if ($value === null) {
            return true;
        }

        if ($definition->allowedValues?->ast !== null && !$this->allowedValuesSatisfied($value, $definition)) {
            return false;
        }

        if ($definition->components !== []) {
            if (!Ops::isContext($value)) {
                return true;
            }

            $members = Ops::contextToArray($value);

            foreach ($definition->components as $component) {
                if (!$this->definitionConstraints($members[$component->name] ?? null, $component, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        if ($definition->typeRef !== null) {
            return $this->satisfiesConstraints($value, $definition->typeRef, $depth + 1);
        }

        return true;
    }

    private function allowedValuesSatisfied(mixed $value, ItemDefinition $definition): bool
    {
        // The constraint parsed with the model; an unparseable one never
        // rejects values (the parse failure is a validation message).
        $tests = $definition->allowedValues?->ast;

        if ($tests === null) {
            return true;
        }

        $evaluator = $this->constraintEvaluator ??= new FeelEvaluator(new DiagnosticCollector());

        return $evaluator->test($tests, $value, new Scope([])) === true;
    }
}
