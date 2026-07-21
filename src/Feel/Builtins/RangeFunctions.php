<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Builtins;

use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ops;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelRange;

/**
 * Range (interval algebra) functions per DMN 1.5 table 78: before, after,
 * meets, overlaps, includes, ... over points and ranges. Unbounded range
 * ends are treated as infinities.
 *
 * @internal
 */
final class RangeFunctions
{
    private function __construct()
    {
    }

    public static function register(BuiltinCatalog $catalog): void
    {
        $signatures = [
            [['point1', 'point2'], 2],
            [['point', 'range'], 2],
            [['range', 'point'], 2],
            [['range1', 'range2'], 2],
        ];

        $define = static function (string $name, \Closure $impl) use ($catalog, $signatures): void {
            $catalog->register($name, $signatures, $impl);
        };

        $before = static fn(array $args): mixed => self::before($args[0], $args[1]);
        $define('before', $before);
        $define('after', static fn(array $args): mixed => self::before($args[1], $args[0]));

        $define('meets', static function (array $args): mixed {
            [$a, $b] = self::ranges($args, 'meets');

            return $a->endIncluded && $b->startIncluded && self::cmp($a->end, $b->start) === 0;
        });

        $define('met by', static function (array $args): mixed {
            [$b, $a] = self::ranges($args, 'met by');

            return $a->endIncluded && $b->startIncluded && self::cmp($a->end, $b->start) === 0;
        });

        $define('overlaps', static function (array $args): mixed {
            [$a, $b] = self::ranges($args, 'overlaps');

            return self::overlaps($a, $b);
        });

        $define('overlaps before', static function (array $args): mixed {
            [$a, $b] = self::ranges($args, 'overlaps before');

            $startsBefore = self::boundCmp($a->start, true, $b->start, true) < 0
                || (self::boundCmp($a->start, true, $b->start, true) === 0
                    && $a->startIncluded && !$b->startIncluded);
            $endsWithin = self::boundCmp($a->end, false, $b->end, false) < 0
                || (self::boundCmp($a->end, false, $b->end, false) === 0
                    && (!$a->endIncluded || $b->endIncluded));

            return $startsBefore && $endsWithin && self::overlaps($a, $b);
        });

        $define('overlaps after', static function (array $args): mixed {
            [$b, $a] = self::ranges($args, 'overlaps after');

            $startsBefore = self::boundCmp($a->start, true, $b->start, true) < 0
                || (self::boundCmp($a->start, true, $b->start, true) === 0
                    && $a->startIncluded && !$b->startIncluded);
            $endsWithin = self::boundCmp($a->end, false, $b->end, false) < 0
                || (self::boundCmp($a->end, false, $b->end, false) === 0
                    && (!$a->endIncluded || $b->endIncluded));

            return $startsBefore && $endsWithin && self::overlaps($a, $b);
        });

        $define('finishes', static function (array $args): mixed {
            if (!$args[0] instanceof FeelRange) {
                $range = self::range($args[1], 'finishes', 'range');

                return $range->endIncluded && self::cmp($args[0], $range->end) === 0;
            }

            [$a, $b] = self::ranges($args, 'finishes');

            return $a->endIncluded === $b->endIncluded
                && self::boundCmp($a->end, false, $b->end, false) === 0
                && (self::boundCmp($a->start, true, $b->start, true) > 0
                    || (self::boundCmp($a->start, true, $b->start, true) === 0
                        && (!$a->startIncluded || $b->startIncluded)));
        });

        $define('finished by', static function (array $args): mixed {
            $a = self::range($args[0], 'finished by', 'range');

            if (!$args[1] instanceof FeelRange) {
                return $a->endIncluded && self::cmp($args[1], $a->end) === 0;
            }

            $b = $args[1];

            return $b->endIncluded === $a->endIncluded
                && self::boundCmp($b->end, false, $a->end, false) === 0
                && (self::boundCmp($b->start, true, $a->start, true) > 0
                    || (self::boundCmp($b->start, true, $a->start, true) === 0
                        && (!$b->startIncluded || $a->startIncluded)));
        });

        $define('includes', static function (array $args): mixed {
            $range = self::range($args[0], 'includes', 'range');

            if (!$args[1] instanceof FeelRange) {
                return $range->includes($args[1]) === true;
            }

            return self::rangeIncludes($range, $args[1]);
        });

        $define('during', static function (array $args): mixed {
            $range = self::range($args[1], 'during', 'range');

            if (!$args[0] instanceof FeelRange) {
                return $range->includes($args[0]) === true;
            }

            return self::rangeIncludes($range, $args[0]);
        });

        $define('starts', static function (array $args): mixed {
            $range = self::range($args[1], 'starts', 'range');

            if (!$args[0] instanceof FeelRange) {
                return $range->startIncluded && self::cmp($args[0], $range->start) === 0;
            }

            $a = $args[0];

            return $a->startIncluded === $range->startIncluded
                && self::boundCmp($a->start, true, $range->start, true) === 0
                && (self::boundCmp($a->end, false, $range->end, false) < 0
                    || (self::boundCmp($a->end, false, $range->end, false) === 0
                        && (!$a->endIncluded || $range->endIncluded)));
        });

        $define('started by', static function (array $args): mixed {
            $range = self::range($args[0], 'started by', 'range');

            if (!$args[1] instanceof FeelRange) {
                return $range->startIncluded && self::cmp($args[1], $range->start) === 0;
            }

            $a = $args[1];

            return $a->startIncluded === $range->startIncluded
                && self::boundCmp($a->start, true, $range->start, true) === 0
                && (self::boundCmp($a->end, false, $range->end, false) < 0
                    || (self::boundCmp($a->end, false, $range->end, false) === 0
                        && (!$a->endIncluded || $range->endIncluded)));
        });

        $define('coincides', static function (array $args): mixed {
            if (!$args[0] instanceof FeelRange && !$args[1] instanceof FeelRange) {
                return self::cmp($args[0], $args[1]) === 0;
            }

            [$a, $b] = self::ranges($args, 'coincides');

            return $a->startIncluded === $b->startIncluded
                && $a->endIncluded === $b->endIncluded
                && self::boundCmp($a->start, true, $b->start, true) === 0
                && self::boundCmp($a->end, false, $b->end, false) === 0;
        });
    }

    private static function before(mixed $a, mixed $b): bool
    {
        if (!$a instanceof FeelRange && !$b instanceof FeelRange) {
            return self::cmp($a, $b) < 0;
        }

        if (!$a instanceof FeelRange) {
            \assert($b instanceof FeelRange);
            $comparison = self::boundCmp($a, true, $b->start, true);

            return $comparison < 0 || ($comparison === 0 && !$b->startIncluded);
        }

        if (!$b instanceof FeelRange) {
            $comparison = self::boundCmp($a->end, false, $b, false);

            return $comparison < 0 || ($comparison === 0 && !$a->endIncluded);
        }

        $comparison = self::boundCmp($a->end, false, $b->start, true);

        return $comparison < 0 || ($comparison === 0 && (!$a->endIncluded || !$b->startIncluded));
    }

    private static function overlaps(FeelRange $a, FeelRange $b): bool
    {
        $aEndVsBStart = self::boundCmp($a->end, false, $b->start, true);
        $bEndVsAStart = self::boundCmp($b->end, false, $a->start, true);

        return ($aEndVsBStart > 0 || ($aEndVsBStart === 0 && $a->endIncluded && $b->startIncluded))
            && ($bEndVsAStart > 0 || ($bEndVsAStart === 0 && $b->endIncluded && $a->startIncluded));
    }

    private static function rangeIncludes(FeelRange $outer, FeelRange $inner): bool
    {
        $startComparison = self::boundCmp($inner->start, true, $outer->start, true);
        $endComparison = self::boundCmp($inner->end, false, $outer->end, false);

        return ($startComparison > 0
                || ($startComparison === 0 && ($outer->startIncluded || !$inner->startIncluded)))
            && ($endComparison < 0
                || ($endComparison === 0 && ($outer->endIncluded || !$inner->endIncluded)));
    }

    /**
     * @param array<mixed> $args
     *
     * @return array{FeelRange, FeelRange}
     */
    private static function ranges(array $args, string $function): array
    {
        $args = array_values($args);

        return [
            self::range($args[0] ?? null, $function, 'range1'),
            self::range($args[1] ?? null, $function, 'range2'),
        ];
    }

    private static function range(mixed $value, string $function, string $parameter): FeelRange
    {
        if (!$value instanceof FeelRange) {
            throw Args::typeError($function, $parameter, 'a range', $value);
        }

        return $value;
    }

    /**
     * Comparison of two present (non-range) values; incomparable → error.
     */
    private static function cmp(mixed $a, mixed $b): int
    {
        if ($a === null || $b === null) {
            throw new FeelError('range function arguments must not be null');
        }

        $comparison = Ops::compare($a, $b);

        if ($comparison === null) {
            throw new FeelError('range function arguments are not comparable');
        }

        return $comparison;
    }

    /**
     * Bound comparison where null start = -infinity and null end = +infinity.
     */
    private static function boundCmp(mixed $a, bool $aIsStart, mixed $b, bool $bIsStart): int
    {
        if ($a === null && $b === null) {
            return $aIsStart === $bIsStart ? 0 : ($aIsStart ? -1 : 1);
        }

        if ($a === null) {
            return $aIsStart ? -1 : 1;
        }

        if ($b === null) {
            return $bIsStart ? 1 : -1;
        }

        return self::cmp($a, $b);
    }
}
