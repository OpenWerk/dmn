<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Parser;

/**
 * The names known in scope while parsing a FEEL expression. FEEL names may
 * contain spaces (`monthly salary`) and keyword-adjacent parts (`is active`),
 * so the parser resolves runs of adjacent name tokens against this registry,
 * preferring the longest known match.
 *
 * @internal
 */
final class NameRegistry
{
    /** @var array<string, true> */
    private array $names = [];

    private int $maxParts = 1;

    public static function withBuiltins(): self
    {
        $registry = new self();

        foreach (\OpenWerk\DecisionModelAndNotation\Feel\Builtins\BuiltinCatalog::standard()->names() as $name) {
            $registry->add($name);
        }

        return $registry;
    }

    public function add(string $name): void
    {
        $normalized = self::normalize($name);

        if ($normalized === '') {
            return;
        }

        $this->names[$normalized] = true;
        // Each space joins two tokens; each additional name symbol
        // (. - + * /) is itself a token between two.
        $symbols = \strlen($normalized) - \strlen(str_replace(['.', '-', '+', '*', '/'], '', $normalized));
        $this->maxParts = max(
            $this->maxParts,
            substr_count($normalized, ' ') + 2 * $symbols + 1,
        );
    }

    public function has(string $name): bool
    {
        return isset($this->names[self::normalize($name)]);
    }

    /**
     * The largest number of space-separated parts of any registered name;
     * bounds the parser's lookahead when recombining multi-word names.
     */
    public function maxParts(): int
    {
        return $this->maxParts;
    }

    public static function normalize(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }
}
