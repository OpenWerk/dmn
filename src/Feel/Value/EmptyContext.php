<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Value;

/**
 * The empty FEEL context `{}`.
 *
 * PHP cannot distinguish an empty associative array from an empty list, so
 * the empty context gets its own value type — `{} = []` is a kind mismatch
 * (null), `context put({}, ...)` works while `context put([], ...)` errors,
 * and `{} instance of context` holds.
 */
final class EmptyContext
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
}
