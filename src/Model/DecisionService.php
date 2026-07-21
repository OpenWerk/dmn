<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * A decision service: reusable decision logic invocable as a FEEL function.
 * Its parameters are the input data followed by the input decisions; its
 * result is the single output decision's value, or a context of all output
 * decision values.
 */
final class DecisionService
{
    /**
     * @param list<string> $outputDecisions
     * @param list<string> $encapsulatedDecisions
     * @param list<string> $inputDecisions
     * @param list<string> $inputData
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $outputDecisions = [],
        public readonly array $encapsulatedDecisions = [],
        public readonly array $inputDecisions = [],
        public readonly array $inputData = [],
        public readonly ?Variable $variable = null,
    ) {
    }
}
