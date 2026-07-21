<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Semantics;

/**
 * FEEL three-valued (Kleene) logic over true / false / null.
 *
 * @internal
 */
final class Ternary
{
    private function __construct()
    {
    }

    public static function andAll(?bool ...$values): ?bool
    {
        $sawNull = false;

        foreach ($values as $value) {
            if ($value === false) {
                return false;
            }

            if ($value === null) {
                $sawNull = true;
            }
        }

        return $sawNull ? null : true;
    }

    public static function orAll(?bool ...$values): ?bool
    {
        $sawNull = false;

        foreach ($values as $value) {
            if ($value === true) {
                return true;
            }

            if ($value === null) {
                $sawNull = true;
            }
        }

        return $sawNull ? null : false;
    }

    public static function negate(?bool $value): ?bool
    {
        return $value === null ? null : !$value;
    }
}
