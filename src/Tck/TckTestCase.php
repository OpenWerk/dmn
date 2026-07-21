<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tck;

/**
 * @internal
 */
final class TckTestCase
{
    /**
     * @param array<string, mixed>                $inputs           FEEL values keyed by input data name
     * @param list<TckExpectation>                $expectations
     * @param array<string, array<string, mixed>> $namespacedInputs values for input nodes that carry a
     *                                                              model namespace (imported input data)
     * @param ?string                             $invocableName    decision service (or decision) to invoke
     *                                                              directly instead of the result nodes
     */
    public function __construct(
        public readonly string $id,
        public readonly array $inputs,
        public readonly array $expectations,
        public readonly array $namespacedInputs = [],
        public readonly ?string $invocableName = null,
    ) {
    }
}
