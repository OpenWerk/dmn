<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Value;

/**
 * An invocable FEEL value — currently the runtime shape of a business
 * knowledge model bound into a decision's scope. Created per evaluation run;
 * never part of the serializable model.
 */
final class FeelFunction
{
    /**
     * @param list<string>                 $parameterNames
     * @param \Closure(list<mixed>): mixed $implementation
     */
    public function __construct(
        public readonly array $parameterNames,
        private readonly \Closure $implementation,
    ) {
    }

    /**
     * @param list<mixed> $arguments
     */
    public function invoke(array $arguments): mixed
    {
        return ($this->implementation)($arguments);
    }
}
