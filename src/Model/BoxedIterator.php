<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * A boxed iterator expression (DMN 1.4+): for / some / every over `in`,
 * binding the iterator variable per item.
 */
final class BoxedIterator implements Expression
{
    public const string KIND_FOR = 'for';
    public const string KIND_SOME = 'some';
    public const string KIND_EVERY = 'every';

    /**
     * @param string $kind one of the KIND_* constants
     */
    public function __construct(
        public readonly ?string $id,
        public readonly string $kind,
        public readonly string $iteratorVariable,
        public readonly ?Expression $in,
        public readonly ?Expression $body,
    ) {
    }
}
