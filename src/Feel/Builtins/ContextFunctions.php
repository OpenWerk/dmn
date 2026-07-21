<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Builtins;

use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ops;
use OpenWerk\DecisionModelAndNotation\Feel\Value\EmptyContext;

/**
 * Context functions (DMN 1.5, 10.3.4.8): get value, get entries,
 * context put, context merge.
 *
 * @internal
 */
final class ContextFunctions
{
    private function __construct()
    {
    }

    public static function register(BuiltinCatalog $catalog): void
    {
        $catalog->register(
            'get value',
            [[['m', 'key'], 2]],
            static function (array $args): mixed {
                $context = Args::context($args[0], 'get value', 'm');
                $key = Args::string($args[1], 'get value', 'key');

                return $context[$key] ?? null;
            },
        );

        $catalog->register(
            'get entries',
            [[['m'], 1]],
            static function (array $args): mixed {
                $entries = [];

                foreach (Args::context($args[0], 'get entries', 'm') as $key => $value) {
                    $entries[] = ['key' => (string) $key, 'value' => $value];
                }

                return $entries;
            },
        );

        $catalog->register(
            'context put',
            [[['context', 'key', 'value'], 3], [['context', 'keys', 'value'], 3]],
            static function (array $args, array $provided, int $namedSignature): mixed {
                $context = Args::context($args[0], 'context put', 'context');

                if ($namedSignature === 0 && !\is_string($args[1])) {
                    throw Args::typeError('context put', 'key', 'a string', $args[1]);
                }

                if ($namedSignature === 1 && !Ops::isList($args[1])) {
                    throw Args::typeError('context put', 'keys', 'a list of keys', $args[1]);
                }

                if (\is_string($args[1])) {
                    $context[$args[1]] = $args[2];

                    return $context;
                }

                if (!Ops::isList($args[1])) {
                    throw Args::typeError('context put', 'keys', 'a string key or a list of keys', $args[1]);
                }

                \assert(\is_array($args[1]));

                return self::putPath($context, array_values($args[1]), $args[2]);
            },
        );

        $catalog->register(
            'context merge',
            [[['contexts'], 1]],
            static function (array $args): mixed {
                // A single context coerces to a singleton list (decision012).
                $contexts = Ops::isList($args[0]) ? (array) $args[0] : [$args[0]];
                $merged = [];

                foreach ($contexts as $context) {
                    foreach (Args::context($context, 'context merge', 'contexts') as $key => $value) {
                        $merged[$key] = $value;
                    }
                }

                return $merged === [] ? EmptyContext::instance() : $merged;
            },
        );
    }

    /**
     * @param array<array-key, mixed> $context
     * @param list<mixed>             $keys
     *
     * @return array<array-key, mixed>
     */
    private static function putPath(array $context, array $keys, mixed $value): array
    {
        if ($keys === []) {
            throw new FeelError('context put(): keys must not be empty');
        }

        $key = $keys[0];

        if (!\is_string($key)) {
            throw Args::typeError('context put', 'keys', 'a list of string keys', $key);
        }

        if (\count($keys) === 1) {
            $context[$key] = $value;

            return $context;
        }

        // Descending replaces context values and fills in missing keys, but
        // never silently overwrites a non-context value (nested009).
        if (\array_key_exists($key, $context) && !Ops::isContext($context[$key])) {
            throw new FeelError(sprintf(
                'context put(): value at key %s is not a context',
                var_export($key, true),
            ));
        }

        $inner = isset($context[$key]) && Ops::isContext($context[$key])
            ? Ops::contextToArray($context[$key])
            : [];

        $context[$key] = self::putPath($inner, \array_slice($keys, 1), $value);

        return $context;
    }
}
