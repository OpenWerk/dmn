<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Builtins;

use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ops;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ternary;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDateTime;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelTime;
use OpenWerk\DecisionModelAndNotation\Feel\Value\ZoneKind;

/**
 * Boolean and miscellaneous functions: not(), is().
 *
 * @internal
 */
final class BooleanFunctions
{
    private function __construct()
    {
    }

    public static function register(BuiltinCatalog $catalog): void
    {
        $catalog->register(
            'not',
            [[['negand'], 1]],
            static function (array $args): mixed {
                $value = Args::unwrapSingleton($args[0]);

                return Ternary::negate(\is_bool($value) ? $value : null);
            },
        );

        $catalog->register(
            'is',
            // Either value may be omitted: the missing side compares as null.
            [[['value1', 'value2'], 0]],
            static function (array $args): mixed {
                [$a, $b] = [$args[0], $args[1]];

                if ($a === null && $b === null) {
                    return true;
                }

                // is() distinguishes zone-id and offset flavors that plain
                // equality treats as the same instant.
                if ($a instanceof FeelTime && $b instanceof FeelTime) {
                    return $a->hour === $b->hour
                        && $a->minute === $b->minute
                        && $a->second === $b->second
                        && $a->microsecond === $b->microsecond
                        && $a->offsetSeconds === $b->offsetSeconds
                        && $a->zoneId === $b->zoneId;
                }

                if ($a instanceof FeelDateTime && $b instanceof FeelDateTime) {
                    return $a->kind === $b->kind
                        && $a->dateTime->format('Y-m-d H:i:s.u') === $b->dateTime->format('Y-m-d H:i:s.u')
                        && ($a->kind === ZoneKind::Local || $a->dateTime->getOffset() === $b->dateTime->getOffset())
                        && ($a->kind !== ZoneKind::Zone
                            || $a->dateTime->getTimezone()->getName() === $b->dateTime->getTimezone()->getName());
                }

                return Ops::typeName($a) === Ops::typeName($b) && Ops::equals($a, $b) === true;
            },
        );
    }
}
