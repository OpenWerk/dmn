<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Builtins;

/**
 * A built-in FEEL function: one or more signatures (for named-argument
 * binding and arity checks) plus the implementation.
 *
 * Implementations receive `(arguments, provided, namedSignature)`; the
 * `provided` flags distinguish explicitly passed nulls from omitted optional
 * parameters, and `namedSignature` is the index of the signature named
 * arguments were bound against (-1 for positional calls). Closures that do
 * not care simply omit the extra parameters.
 *
 * @internal
 */
final class BuiltinFunction
{
    /**
     * @param list<array{list<string>, int}> $signatures     [parameter names, required count] alternatives
     * @param \Closure                       $implementation (list<mixed>, list<bool>, int): mixed
     * @param bool                           $variadic       accepts any number of positional arguments
     */
    public function __construct(
        public readonly string $name,
        public readonly array $signatures,
        private readonly \Closure $implementation,
        public readonly bool $variadic = false,
    ) {
    }

    /**
     * Maps positional or named arguments onto one of the signatures.
     * Returns [arguments, provided flags, named signature index] or null
     * when nothing matches.
     *
     * @param list<mixed>          $positional
     * @param array<string, mixed> $named
     *
     * @return array{list<mixed>, list<bool>, int}|null
     */
    public function bind(array $positional, array $named): ?array
    {
        if ($named !== []) {
            if ($positional !== []) {
                return null; // FEEL forbids mixing positional and named arguments
            }

            foreach ($this->signatures as $signatureIndex => [$parameters, $required]) {
                if (array_diff(array_keys($named), $parameters) !== []) {
                    continue;
                }

                $bound = [];
                $provided = [];
                $satisfied = true;

                foreach ($parameters as $index => $parameter) {
                    if (\array_key_exists($parameter, $named)) {
                        $bound[] = $named[$parameter];
                        $provided[] = true;
                    } elseif ($index < $required) {
                        $satisfied = false;
                        break;
                    } else {
                        $bound[] = null;
                        $provided[] = false;
                    }
                }

                if ($satisfied) {
                    return [$bound, $provided, $signatureIndex];
                }
            }

            return null;
        }

        if ($this->variadic) {
            return [$positional, array_fill(0, \count($positional), true), -1];
        }

        foreach ($this->signatures as [$parameters, $required]) {
            $count = \count($positional);

            if ($count >= $required && $count <= \count($parameters)) {
                $padding = \count($parameters) - $count;

                return [
                    [...$positional, ...array_fill(0, $padding, null)],
                    [...array_fill(0, $count, true), ...array_fill(0, $padding, false)],
                    -1,
                ];
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $arguments
     * @param list<bool>  $provided
     */
    public function invoke(array $arguments, array $provided = [], int $namedSignature = -1): mixed
    {
        if ($provided === []) {
            $provided = array_fill(0, \count($arguments), true);
        }

        return ($this->implementation)($arguments, $provided, $namedSignature);
    }
}
