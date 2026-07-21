<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Evaluator;

/**
 * An immutable variable scope with parent chaining. Values are FEEL runtime
 * values (null, bool, string, FeelNumber, temporal values, lists, contexts).
 *
 * @internal
 */
final class Scope
{
    /**
     * @param array<string, mixed> $variables
     */
    public function __construct(
        private readonly array $variables = [],
        private readonly ?Scope $parent = null,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->variables) || $this->parent?->has($name) === true;
    }

    public function get(string $name): mixed
    {
        if (\array_key_exists($name, $this->variables)) {
            return $this->variables[$name];
        }

        return $this->parent?->get($name);
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function child(array $variables): self
    {
        return new self($variables, $this);
    }
}
