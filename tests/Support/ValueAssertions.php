<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Narrowing helpers for FEEL runtime values (which are `mixed` by design).
 */
trait ValueAssertions
{
    private static function str(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        Assert::fail('expected a string-representable value, got ' . get_debug_type($value));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arr(mixed $value): array
    {
        if (!\is_array($value)) {
            Assert::fail('expected an array, got ' . get_debug_type($value));
        }

        return $value;
    }
}
