<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation;

/**
 * Introspection record for one invocable model element — a decision, a
 * business knowledge model or a decision service — with its declared
 * parameter and result types.
 *
 * What "parameters" means per kind:
 *
 *  - decision: the input data it transitively requires — the values callers
 *    pass to {@see DmnModel::evaluateDecision()}
 *  - business knowledge model: its formal parameters (or the implicit
 *    parameters of a decision table used as its logic)
 *  - decision service: its input data followed by its input decisions, in
 *    declaration order — both the values callers pass to
 *    {@see DmnModel::evaluateService()} and the FEEL invocation arguments
 *
 * Type references are reported as declared in the model (FEEL base types or
 * item definition names); null when the model declares none.
 */
final class Invocable
{
    /**
     * @param list<InvocableParameter> $parameters
     */
    public function __construct(
        public readonly InvocableKind $kind,
        public readonly string $id,
        public readonly string $name,
        public readonly array $parameters,
        public readonly ?string $resultTypeRef = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function parameterNames(): array
    {
        return array_map(
            static fn(InvocableParameter $parameter): string => $parameter->name,
            $this->parameters,
        );
    }
}
