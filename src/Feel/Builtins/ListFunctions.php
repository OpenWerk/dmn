<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Builtins;

use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ops;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ternary;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelFunction;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;

/**
 * List functions (DMN 1.5, 10.3.4.4). The aggregating functions
 * (min/max/sum/...) accept either a single list or variadic arguments.
 *
 * @internal
 */
final class ListFunctions
{
    private function __construct()
    {
    }

    public static function register(BuiltinCatalog $catalog): void
    {
        $catalog->register(
            'list contains',
            [[['list', 'element'], 2]],
            static function (array $args): mixed {
                foreach (Args::listOf($args[0], 'list contains', 'list') as $item) {
                    if (self::same($item, $args[1])) {
                        return true;
                    }
                }

                return false;
            },
        );

        $catalog->register(
            'count',
            [[['list'], 1]],
            static fn(array $args): mixed => FeelNumber::of(\count(Args::listOf($args[0], 'count', 'list'))),
        );

        $catalog->register('min', [[['list'], 1]], self::extremum(-1), variadic: true);
        $catalog->register('max', [[['list'], 1]], self::extremum(1), variadic: true);

        $catalog->register(
            'sum',
            [[['list'], 1]],
            static function (array $args): mixed {
                $numbers = self::numbers(self::gather($args), 'sum');

                if ($numbers === []) {
                    return null;
                }

                $sum = $numbers[0];

                foreach (\array_slice($numbers, 1) as $number) {
                    $sum = $sum->plus($number);
                }

                return $sum;
            },
            variadic: true,
        );

        $catalog->register(
            'product',
            [[['list'], 1]],
            static function (array $args): mixed {
                $numbers = self::numbers(self::gather($args), 'product');

                if ($numbers === []) {
                    return null;
                }

                $product = $numbers[0];

                foreach (\array_slice($numbers, 1) as $number) {
                    $product = $product->multipliedBy($number);
                }

                return $product;
            },
            variadic: true,
        );

        $catalog->register(
            'mean',
            [[['list'], 1]],
            static function (array $args): mixed {
                $numbers = self::numbers(self::gather($args), 'mean');

                if ($numbers === []) {
                    return null;
                }

                $sum = $numbers[0];

                foreach (\array_slice($numbers, 1) as $number) {
                    $sum = $sum->plus($number);
                }

                return $sum->dividedBy(FeelNumber::of(\count($numbers)));
            },
            variadic: true,
        );

        $catalog->register(
            'median',
            [[['list'], 1]],
            static function (array $args): mixed {
                $numbers = self::numbers(self::gather($args), 'median');

                if ($numbers === []) {
                    return null;
                }

                usort($numbers, static fn(FeelNumber $a, FeelNumber $b): int => $a->compareTo($b));
                $count = \count($numbers);
                $middle = intdiv($count, 2);

                if ($count % 2 === 1) {
                    return $numbers[$middle];
                }

                return $numbers[$middle - 1]->plus($numbers[$middle])->dividedBy(FeelNumber::of(2));
            },
            variadic: true,
        );

        $catalog->register(
            'stddev',
            [[['list'], 1]],
            static function (array $args): mixed {
                $numbers = self::numbers(self::gather($args), 'stddev');
                $count = \count($numbers);

                if ($count < 2) {
                    return null;
                }

                $sum = FeelNumber::of(0);

                foreach ($numbers as $number) {
                    $sum = $sum->plus($number);
                }

                $mean = $sum->dividedBy(FeelNumber::of($count));
                $squares = FeelNumber::of(0);

                foreach ($numbers as $number) {
                    $delta = $number->minus($mean);
                    $squares = $squares->plus($delta->multipliedBy($delta));
                }

                $variance = $squares->dividedBy(FeelNumber::of($count - 1));
                $result = sqrt((float) (string) $variance->toBigDecimal());

                if (!is_finite($result)) {
                    throw new FeelError('stddev(): result is not finite');
                }

                return FeelNumber::of($result);
            },
            variadic: true,
        );

        $catalog->register(
            'mode',
            [[['list'], 1]],
            static function (array $args, array $provided): mixed {
                if (($provided[0] ?? false) === false) {
                    throw new FeelError('mode(): a list (or at least one value) is required');
                }

                $values = self::gather($args);
                $distinct = [];
                $counts = [];

                foreach ($values as $value) {
                    foreach ($distinct as $index => $seen) {
                        if (self::same($seen, $value)) {
                            $counts[$index]++;
                            continue 2;
                        }
                    }

                    $distinct[] = $value;
                    $counts[] = 1;
                }

                $best = $counts === [] ? 0 : max($counts);
                $modes = [];

                foreach ($distinct as $index => $value) {
                    if ($counts[$index] === $best) {
                        $modes[] = $value;
                    }
                }

                usort($modes, static function (mixed $a, mixed $b): int {
                    try {
                        return Ops::compare($a, $b) ?? 0;
                    } catch (FeelError) {
                        return 0;
                    }
                });

                return $modes;
            },
            variadic: true,
        );

        $catalog->register(
            'all',
            [[['list'], 1]],
            static function (array $args): mixed {
                // all() with no arguments is an error; all([]) is true.
                if ($args === []) {
                    throw new FeelError('all(): expects at least one argument');
                }

                return Ternary::andAll(...array_map(
                    static fn(mixed $item): ?bool => \is_bool($item) ? $item : null,
                    self::gather($args),
                ));
            },
            variadic: true,
        );

        $catalog->register(
            'any',
            [[['list'], 1]],
            static function (array $args): mixed {
                // any() with no arguments is an error; any([]) is false.
                if ($args === []) {
                    throw new FeelError('any(): expects at least one argument');
                }

                return Ternary::orAll(...array_map(
                    static fn(mixed $item): ?bool => \is_bool($item) ? $item : null,
                    self::gather($args),
                ));
            },
            variadic: true,
        );

        $catalog->register(
            'sublist',
            [[['list', 'start position', 'length'], 2]],
            static function (array $args, array $provided): mixed {
                $list = Args::listOf($args[0], 'sublist', 'list');
                $start = Args::integer($args[1], 'sublist', 'start position');
                $length = ($provided[2] ?? false) ? Args::integer($args[2], 'sublist', 'length') : null;

                if ($start === 0) {
                    throw new FeelError('sublist(): start position must not be zero');
                }

                $index = $start > 0 ? $start - 1 : \count($list) + $start;

                if ($index < 0 || $index >= \count($list)) {
                    throw new FeelError('sublist(): start position is out of range');
                }

                if ($length === null) {
                    return \array_slice($list, $index);
                }

                if ($length < 0 || $index + $length > \count($list)) {
                    throw new FeelError('sublist(): length is out of range');
                }

                return \array_slice($list, $index, $length);
            },
        );

        $catalog->register(
            'append',
            [[['list', 'item'], 1]],
            static function (array $args): mixed {
                if ($args === []) {
                    throw new FeelError('append(): a list argument is required');
                }

                return [...Args::listOf($args[0], 'append', 'list'), ...\array_slice($args, 1)];
            },
            variadic: true,
        );

        $catalog->register(
            'concatenate',
            [[['list'], 1]],
            static function (array $args): mixed {
                $result = [];

                foreach ($args as $index => $arg) {
                    if (!Ops::isList($arg)) {
                        throw Args::typeError('concatenate', 'list ' . ($index + 1), 'a list', $arg);
                    }

                    \assert(\is_array($arg));
                    $result = [...$result, ...array_values($arg)];
                }

                return $result;
            },
            variadic: true,
        );

        $catalog->register(
            'insert before',
            [[['list', 'position', 'newItem'], 3]],
            static function (array $args): mixed {
                $list = Args::listOf($args[0], 'insert before', 'list');
                $position = Args::integer($args[1], 'insert before', 'position');
                $index = $position > 0 ? $position - 1 : \count($list) + $position;

                if ($position === 0 || $index < 0 || $index >= \count($list)) {
                    throw new FeelError('insert before(): position is out of range');
                }

                array_splice($list, $index, 0, [$args[2]]);

                return $list;
            },
        );

        $catalog->register(
            'remove',
            [[['list', 'position'], 2]],
            static function (array $args): mixed {
                $list = Args::listOf($args[0], 'remove', 'list');
                $position = Args::integer($args[1], 'remove', 'position');
                $index = $position > 0 ? $position - 1 : \count($list) + $position;

                if ($position === 0 || $index < 0 || $index >= \count($list)) {
                    throw new FeelError('remove(): position is out of range');
                }

                array_splice($list, $index, 1);

                return $list;
            },
        );

        $catalog->register(
            'reverse',
            [[['list'], 1]],
            static fn(array $args): mixed => array_reverse(Args::listOf($args[0], 'reverse', 'list')),
        );

        $catalog->register(
            'index of',
            [[['list', 'match'], 2]],
            static function (array $args): mixed {
                $positions = [];

                foreach (Args::listOf($args[0], 'index of', 'list') as $index => $item) {
                    if (self::same($item, $args[1])) {
                        $positions[] = FeelNumber::of($index + 1);
                    }
                }

                return $positions;
            },
        );

        $catalog->register(
            'union',
            [[['list'], 1]],
            static function (array $args): mixed {
                $result = [];

                foreach ($args as $index => $arg) {
                    if (!Ops::isList($arg)) {
                        throw Args::typeError('union', 'list ' . ($index + 1), 'a list', $arg);
                    }

                    \assert(\is_array($arg));

                    foreach ($arg as $item) {
                        if (!self::containsSame($result, $item)) {
                            $result[] = $item;
                        }
                    }
                }

                return $result;
            },
            variadic: true,
        );

        $catalog->register(
            'distinct values',
            [[['list'], 1]],
            static function (array $args): mixed {
                $result = [];

                foreach (Args::listOf($args[0], 'distinct values', 'list') as $item) {
                    if (!self::containsSame($result, $item)) {
                        $result[] = $item;
                    }
                }

                return $result;
            },
        );

        $catalog->register(
            'duplicate values',
            [[['list'], 1]],
            static function (array $args): mixed {
                $list = Args::listOf($args[0], 'duplicate values', 'list');
                $seen = [];
                $duplicates = [];

                foreach ($list as $item) {
                    if (self::containsSame($seen, $item)) {
                        if (!self::containsSame($duplicates, $item)) {
                            $duplicates[] = $item;
                        }
                    } else {
                        $seen[] = $item;
                    }
                }

                return $duplicates;
            },
        );

        $catalog->register(
            'flatten',
            [[['list'], 1]],
            static fn(array $args): mixed => self::flattenValue(Args::listOf($args[0], 'flatten', 'list')),
        );

        $catalog->register(
            'sort',
            [[['list', 'precedes'], 1]],
            static function (array $args): mixed {
                $list = Args::listOf($args[0], 'sort', 'list');

                if ($args[1] === null) {
                    usort($list, static function (mixed $a, mixed $b): int {
                        $comparison = Ops::compare($a, $b);

                        if ($comparison === null) {
                            throw new FeelError('sort(): list items are not comparable');
                        }

                        return $comparison;
                    });

                    return $list;
                }

                $precedes = Args::function($args[1], 'sort', 'precedes');
                usort($list, static fn(mixed $a, mixed $b): int => $precedes->invoke([$a, $b]) === true ? -1 : 1);

                return $list;
            },
        );

        $catalog->register(
            'list replace',
            [[['list', 'position', 'newItem'], 3], [['list', 'match', 'newItem'], 3]],
            static function (array $args, array $provided, int $namedSignature): mixed {
                $list = Args::listOf($args[0], 'list replace', 'list');

                if ($namedSignature === 0 && $args[1] instanceof FeelFunction) {
                    throw Args::typeError('list replace', 'position', 'an integer', $args[1]);
                }

                if ($args[1] instanceof FeelFunction) {
                    if (\count($args[1]->parameterNames) !== 2) {
                        throw new FeelError('list replace(): match must be a function of (item, newItem)');
                    }

                    foreach ($list as $index => $item) {
                        $outcome = $args[1]->invoke([$item, $args[2]]);

                        if (!\is_bool($outcome)) {
                            throw new FeelError('list replace(): match must return a boolean');
                        }

                        if ($outcome) {
                            $list[$index] = $args[2];
                        }
                    }

                    return $list;
                }

                if ($namedSignature === 1) {
                    throw Args::typeError('list replace', 'match', 'a function', $args[1]);
                }

                // A fractional position truncates toward zero (case 011).
                $position = Args::number($args[1], 'list replace', 'position')
                    ->toBigDecimal()
                    ->toScale(0, \Brick\Math\RoundingMode::DOWN)
                    ->toInt();
                $index = $position > 0 ? $position - 1 : \count($list) + $position;

                if ($position === 0 || $index < 0 || $index >= \count($list)) {
                    throw new FeelError('list replace(): position is out of range');
                }

                $list[$index] = $args[2];

                return $list;
            },
        );
    }

    /**
     * @return list<mixed>
     */
    private static function flattenValue(mixed $value): array
    {
        if (!Ops::isList($value)) {
            return [$value];
        }

        \assert(\is_array($value));
        $result = [];

        foreach ($value as $item) {
            $result = [...$result, ...self::flattenValue($item)];
        }

        return $result;
    }

    /**
     * Variadic gathering: a single list argument is the list, several
     * arguments form the list.
     *
     * @param array<mixed> $args
     *
     * @return list<mixed>
     */
    private static function gather(array $args): array
    {
        $args = array_values($args);

        if (\count($args) === 1) {
            return Args::listOf($args[0], 'list function', 'list');
        }

        return $args;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<FeelNumber>
     */
    private static function numbers(array $values, string $function): array
    {
        $numbers = [];

        foreach ($values as $value) {
            if (!$value instanceof FeelNumber) {
                throw Args::typeError($function, 'list', 'a list of numbers', $value);
            }

            $numbers[] = $value;
        }

        return $numbers;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }

        return Ops::equals($a, $b) === true;
    }

    /**
     * @param list<mixed> $haystack
     */
    private static function containsSame(array $haystack, mixed $needle): bool
    {
        foreach ($haystack as $item) {
            if (self::same($item, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return \Closure(list<mixed>): mixed
     */
    private static function extremum(int $direction): \Closure
    {
        return static function (array $args) use ($direction): mixed {
            $values = self::gather($args);

            if ($values === []) {
                return null;
            }

            $best = $values[0];

            foreach (\array_slice($values, 1) as $value) {
                $comparison = Ops::compare($value, $best);

                if ($comparison === null) {
                    throw new FeelError('min()/max(): list items are not comparable');
                }

                if ($comparison * $direction > 0) {
                    $best = $value;
                }
            }

            return $best;
        };
    }
}
