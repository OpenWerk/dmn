<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Semantics;

use OpenWerk\DecisionModelAndNotation\Feel\Ast\TypeNode;
use OpenWerk\DecisionModelAndNotation\Feel\Value\DaysTimeDuration;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDate;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDateTime;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelFunction;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelRange;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelTime;
use OpenWerk\DecisionModelAndNotation\Feel\Value\YearsMonthsDuration;

/**
 * `instance of` conformance per the FEEL type lattice: null conforms to
 * `Any` and `null` only; structured types check element/member conformance.
 *
 * @internal
 */
final class TypeChecker
{
    private function __construct()
    {
    }

    /**
     * @param bool $lenientNulls treat null elements as conforming — the
     *                           declared-type checks use this (a null list
     *                           element conforms to a typed list), while
     *                           `instance of` stays strict
     */
    public static function conforms(mixed $value, TypeNode $type, bool $lenientNulls = false): bool
    {
        switch ($type->kind) {
            case TypeNode::KIND_LIST:
                if (!Ops::isList($value)) {
                    return false;
                }

                \assert(\is_array($value));

                if ($type->parameters === []) {
                    return true;
                }

                foreach ($value as $item) {
                    if ($lenientNulls && $item === null) {
                        continue;
                    }

                    if (!self::conforms($item, $type->parameters[0], $lenientNulls)) {
                        return false;
                    }
                }

                return true;

            case TypeNode::KIND_RANGE:
                if (!$value instanceof FeelRange) {
                    return false;
                }

                if ($type->parameters === []) {
                    return true;
                }

                foreach ([$value->start, $value->end] as $endpoint) {
                    if ($endpoint !== null && !self::conforms($endpoint, $type->parameters[0], $lenientNulls)) {
                        return false;
                    }
                }

                return true;

            case TypeNode::KIND_CONTEXT:
                if (!Ops::isContext($value)) {
                    return false;
                }

                $members = Ops::contextToArray($value);

                foreach ($type->members as $member => $memberType) {
                    if (!\array_key_exists($member, $members)) {
                        return false;
                    }

                    // A present-but-null member conforms to any member type.
                    if (
                        $members[$member] !== null
                        && !self::conforms($members[$member], $memberType, $lenientNulls)
                    ) {
                        return false;
                    }
                }

                return true;

            case TypeNode::KIND_FUNCTION:
                return $value instanceof FeelFunction;

            default:
                return self::conformsSimple($value, $type->name);
        }
    }

    public static function isBuiltinTypeName(string $name): bool
    {
        return \in_array($name, [
            'Any',
            'null',
            'number',
            'string',
            'boolean',
            'date',
            'time',
            'date and time',
            'days and time duration',
            'years and months duration',
            'list',
            'context',
            'range',
            'function',
        ], true);
    }

    private static function conformsSimple(mixed $value, string $name): bool
    {
        return match ($name) {
            // Per the TCK, null conforms only to the null type, not to Any.
            'Any' => $value !== null,
            'null' => $value === null,
            'number' => $value instanceof FeelNumber,
            'string' => \is_string($value),
            'boolean' => \is_bool($value),
            'date' => $value instanceof FeelDate,
            'time' => $value instanceof FeelTime,
            'date and time' => $value instanceof FeelDateTime,
            'days and time duration' => $value instanceof DaysTimeDuration,
            'years and months duration' => $value instanceof YearsMonthsDuration,
            'list' => Ops::isList($value),
            'context' => Ops::isContext($value),
            'range' => $value instanceof FeelRange,
            'function' => $value instanceof FeelFunction,
            default => false,
        };
    }
}
