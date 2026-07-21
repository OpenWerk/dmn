<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * One `name in domain` binding of a for/some/every expression. The domain
 * is either a list expression or a numeric range `start..end`.
 *
 * @internal
 */
final class IterationContext implements Node
{
    public function __construct(
        public readonly string $name,
        public readonly Node $domain,
        public readonly ?Node $domainEnd = null,
    ) {
    }
}
