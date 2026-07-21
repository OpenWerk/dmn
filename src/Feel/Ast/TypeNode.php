<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * A FEEL type expression as used by `instance of`: a simple type name,
 * `list<T>`, `range<T>`, `context<key: T, ...>` or
 * `function<T, ...> -> T`.
 *
 * @internal
 */
final class TypeNode implements Node
{
    public const string KIND_SIMPLE = 'simple';
    public const string KIND_LIST = 'list';
    public const string KIND_RANGE = 'range';
    public const string KIND_CONTEXT = 'context';
    public const string KIND_FUNCTION = 'function';

    /**
     * @param string                  $kind    one of the KIND_* constants
     * @param string                  $name    the simple type name (KIND_SIMPLE only)
     * @param list<TypeNode>          $parameters element/parameter types
     * @param array<string, TypeNode> $members context member types (KIND_CONTEXT only)
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $name = '',
        public readonly array $parameters = [],
        public readonly array $members = [],
    ) {
    }
}
