<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Builtins;

use OpenWerk\DecisionModelAndNotation\Feel\Parser\NameRegistry;

/**
 * Registry of the FEEL built-in function library (DMN 1.5, 10.3.4).
 *
 * @internal
 */
final class BuiltinCatalog
{
    private static ?self $standard = null;

    /** @var array<string, BuiltinFunction> */
    private array $functions = [];

    public static function standard(): self
    {
        if (self::$standard === null) {
            $catalog = new self();
            ConversionFunctions::register($catalog);
            BooleanFunctions::register($catalog);
            StringFunctions::register($catalog);
            ListFunctions::register($catalog);
            NumericFunctions::register($catalog);
            TemporalFunctions::register($catalog);
            RangeFunctions::register($catalog);
            ContextFunctions::register($catalog);
            self::$standard = $catalog;
        }

        return self::$standard;
    }

    /**
     * @param list<array{list<string>, int}> $signatures
     * @param \Closure                       $implementation (list<mixed>, list<bool>, int): mixed
     */
    public function register(
        string $name,
        array $signatures,
        \Closure $implementation,
        bool $variadic = false,
    ): void {
        $this->functions[$name] = new BuiltinFunction($name, $signatures, $implementation, $variadic);
    }

    public function get(string $name): ?BuiltinFunction
    {
        // Names arriving from the parser are already whitespace-normalized,
        // so the direct hit avoids the normalizing regex on the hot path.
        return $this->functions[$name] ?? $this->functions[NameRegistry::normalize($name)] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->functions);
    }
}
