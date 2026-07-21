<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation;

use OpenWerk\DecisionModelAndNotation\Diagnostics\Severity;
use OpenWerk\DecisionModelAndNotation\Engine\EvaluationResult;
use OpenWerk\DecisionModelAndNotation\Engine\EvaluationRun;
use OpenWerk\DecisionModelAndNotation\Engine\InputConverter;
use OpenWerk\DecisionModelAndNotation\Exception\UnknownDecisionException;
use OpenWerk\DecisionModelAndNotation\Model\BusinessKnowledgeModel;
use OpenWerk\DecisionModelAndNotation\Model\Decision;
use OpenWerk\DecisionModelAndNotation\Model\DecisionService;
use OpenWerk\DecisionModelAndNotation\Model\DecisionTable;
use OpenWerk\DecisionModelAndNotation\Model\Definitions;
use OpenWerk\DecisionModelAndNotation\Model\Diagram;
use OpenWerk\DecisionModelAndNotation\Model\Import;
use OpenWerk\DecisionModelAndNotation\Model\LiteralExpression;
use OpenWerk\DecisionModelAndNotation\Validation\ValidationMessage;

/**
 * A parsed DMN model: immutable, serializable (cache it with serialize()/
 * unserialize()) and safe to evaluate concurrently — evaluation is
 * deterministic, side-effect free and keeps no global state.
 */
final class DmnModel
{
    /**
     * @param list<ValidationMessage> $validationMessages
     */
    public function __construct(
        private readonly Definitions $definitions,
        private readonly array $validationMessages = [],
    ) {
    }

    public function definitions(): Definitions
    {
        return $this->definitions;
    }

    public function name(): ?string
    {
        return $this->definitions->name;
    }

    /**
     * @return list<string>
     */
    public function decisionNames(): array
    {
        return $this->definitions->decisionNames();
    }

    /**
     * The model's DMNDI diagrams: read-only layout metadata (shapes with
     * bounds, edges with waypoints) for renderers and tooling. Diagram
     * content never affects evaluation.
     *
     * @return list<Diagram>
     */
    public function diagrams(): array
    {
        return $this->definitions->diagrams;
    }

    /**
     * The model's import declarations as structured metadata — DMN model
     * imports (which resolve) and non-DMN imports such as XSD or PMML
     * (which stay metadata), each with namespace, name, import type and
     * location URI.
     *
     * @return list<Import>
     */
    public function imports(): array
    {
        return $this->definitions->imports;
    }

    /**
     * Enumerates everything this model can evaluate or invoke — decisions,
     * business knowledge models and decision services, in model order — with
     * the declared parameter and result types. Imported models are not
     * enumerated; their input data is supplied through the `importedInputs`
     * evaluation argument instead.
     *
     * @return list<Invocable>
     */
    public function invocables(): array
    {
        $invocables = [];

        foreach ($this->definitions->decisions as $decision) {
            $invocables[] = new Invocable(
                InvocableKind::Decision,
                $decision->id,
                $decision->name,
                $this->decisionParameters($decision),
                $decision->variable?->typeRef,
            );
        }

        foreach ($this->definitions->businessKnowledgeModels as $knowledgeModel) {
            $invocables[] = new Invocable(
                InvocableKind::BusinessKnowledgeModel,
                $knowledgeModel->id,
                $knowledgeModel->name,
                self::knowledgeModelParameters($knowledgeModel),
                self::knowledgeModelResultTypeRef($knowledgeModel),
            );
        }

        foreach ($this->definitions->decisionServices as $service) {
            $invocables[] = new Invocable(
                InvocableKind::DecisionService,
                $service->id,
                $service->name,
                $this->serviceParameters($service),
                $service->variable?->typeRef,
            );
        }

        return $invocables;
    }

    /**
     * Static validation findings collected while parsing: unknown
     * references, duplicate names, cyclic requirements, FEEL syntax errors —
     * position-aware (element id and XML line).
     *
     * @return list<ValidationMessage>
     */
    public function validationMessages(): array
    {
        return $this->validationMessages;
    }

    public function hasValidationErrors(): bool
    {
        foreach ($this->validationMessages as $message) {
            if ($message->severity === Severity::Error) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluates a decision (looked up by name, then by id) with the given
     * inputs, resolving its information requirements in dependency order.
     *
     * FEEL evaluation errors never throw: they surface as null results with
     * diagnostics on the returned {@see EvaluationResult}.
     *
     * @param array<string, mixed>                $inputs         input data values keyed by input data name
     * @param array<string, array<string, mixed>> $importedInputs input data for (transitively) imported
     *                                                            models, keyed by model namespace, then name
     * @param ?\DateTimeImmutable                 $now            pins now()/today() (and their zone) for the
     *                                                            whole run — reproducible evaluations for
     *                                                            tests; defaults to the current UTC instant
     */
    public function evaluateDecision(
        string $decisionNameOrId,
        array $inputs = [],
        array $importedInputs = [],
        ?\DateTimeImmutable $now = null,
    ): EvaluationResult {
        $decision = $this->definitions->decision($decisionNameOrId);

        if ($decision === null) {
            throw new UnknownDecisionException(sprintf(
                'unknown decision %s; the model defines: %s',
                var_export($decisionNameOrId, true),
                implode(', ', array_map(
                    static fn(string $name): string => var_export($name, true),
                    $this->definitions->decisionNames(),
                )),
            ));
        }

        $run = new EvaluationRun(
            $this->definitions,
            self::toFeelInputs($inputs),
            null,
            self::toNamespacedFeelInputs($importedInputs),
            $now,
        );

        return $run->run($decision);
    }

    /**
     * Evaluates a decision service (looked up by name, then by id). Input
     * decisions and input data are supplied by the caller; encapsulated and
     * output decisions are evaluated. A single output decision yields its
     * value; multiple outputs compose a context keyed by decision name.
     *
     * @param array<string, mixed>                $inputs         values keyed by input decision / input data name
     * @param array<string, array<string, mixed>> $importedInputs input data for (transitively) imported
     *                                                            models, keyed by model namespace, then name
     * @param ?\DateTimeImmutable                 $now            pins now()/today() (and their zone) for the
     *                                                            whole run — reproducible evaluations for
     *                                                            tests; defaults to the current UTC instant
     */
    public function evaluateService(
        string $serviceNameOrId,
        array $inputs = [],
        array $importedInputs = [],
        ?\DateTimeImmutable $now = null,
    ): EvaluationResult {
        $service = $this->definitions->decisionService($serviceNameOrId);

        if ($service === null) {
            throw new UnknownDecisionException(sprintf(
                'unknown decision service %s',
                var_export($serviceNameOrId, true),
            ));
        }

        $run = new EvaluationRun(
            $this->definitions,
            self::toFeelInputs($inputs),
            null,
            self::toNamespacedFeelInputs($importedInputs),
            $now,
        );

        return $run->runService($service);
    }

    /**
     * @param array<string, mixed> $inputs
     *
     * @return array<string, mixed>
     */
    private static function toFeelInputs(array $inputs): array
    {
        $feelInputs = [];

        foreach ($inputs as $name => $value) {
            $feelInputs[(string) $name] = InputConverter::toFeel($value);
        }

        return $feelInputs;
    }

    /**
     * @param array<string, array<string, mixed>> $importedInputs
     *
     * @return array<string, array<string, mixed>>
     */
    private static function toNamespacedFeelInputs(array $importedInputs): array
    {
        $feelInputs = [];

        foreach ($importedInputs as $namespace => $inputs) {
            $feelInputs[(string) $namespace] = self::toFeelInputs($inputs);
        }

        return $feelInputs;
    }

    /**
     * A decision's parameters are the input data it transitively requires,
     * in requirement-graph discovery order (inputs before the inputs of
     * required decisions), deduplicated by name.
     *
     * @return list<InvocableParameter>
     */
    private function decisionParameters(Decision $decision): array
    {
        $parameters = [];
        $seen = [];
        $visited = [];
        $this->collectRequiredInputData($decision, $parameters, $seen, $visited);

        return $parameters;
    }

    /**
     * @param list<InvocableParameter> $parameters
     * @param array<string, true>      $seen    input data names already collected
     * @param array<string, true>      $visited decision ids already walked (cycle/diamond guard)
     */
    private function collectRequiredInputData(
        Decision $decision,
        array &$parameters,
        array &$seen,
        array &$visited,
    ): void {
        if (isset($visited[$decision->id])) {
            return;
        }

        $visited[$decision->id] = true;

        foreach ($decision->requiredInputs as $ref) {
            // Namespace-qualified requirements live in imported models; their
            // values arrive through the importedInputs evaluation argument.
            if (str_contains($ref, '#')) {
                continue;
            }

            $inputData = $this->definitions->inputDataById($ref);

            if ($inputData === null) {
                continue;
            }

            $name = $inputData->inputName();

            if (isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $parameters[] = new InvocableParameter($name, $inputData->variable?->typeRef);
        }

        foreach ($decision->requiredDecisions as $ref) {
            if (str_contains($ref, '#')) {
                continue;
            }

            $required = $this->definitions->decisionById($ref);

            if ($required !== null) {
                $this->collectRequiredInputData($required, $parameters, $seen, $visited);
            }
        }
    }

    /**
     * A business knowledge model's parameters are its formal parameters, or
     * the implicit parameters of a decision table used as its logic.
     *
     * @return list<InvocableParameter>
     */
    private static function knowledgeModelParameters(BusinessKnowledgeModel $knowledgeModel): array
    {
        $parameters = [];

        foreach ($knowledgeModel->logic->parameters ?? [] as $parameter) {
            $parameters[] = new InvocableParameter($parameter->name, $parameter->typeRef);
        }

        $body = $knowledgeModel->logic?->body;

        if ($parameters === [] && $body instanceof DecisionTable) {
            foreach ($body->implicitParameterNames() as $name) {
                $parameters[] = new InvocableParameter($name);
            }
        }

        return $parameters;
    }

    /**
     * The declared result type of a business knowledge model: its literal
     * expression's type, a single table output's type, or the type of its
     * variable.
     */
    private static function knowledgeModelResultTypeRef(BusinessKnowledgeModel $knowledgeModel): ?string
    {
        $body = $knowledgeModel->logic?->body;

        if ($body instanceof LiteralExpression && $body->typeRef !== null) {
            return $body->typeRef;
        }

        if ($body instanceof DecisionTable && \count($body->outputs) === 1 && $body->outputs[0]->typeRef !== null) {
            return $body->outputs[0]->typeRef;
        }

        return $knowledgeModel->variable?->typeRef;
    }

    /**
     * A decision service's parameters are its input data followed by its
     * input decisions, in declaration order — the exact signature under
     * which FEEL invokes the service.
     *
     * @return list<InvocableParameter>
     */
    private function serviceParameters(DecisionService $service): array
    {
        $parameters = [];

        foreach ($service->inputData as $ref) {
            $inputData = $this->definitions->inputDataById($ref);
            $parameters[] = new InvocableParameter(
                $inputData?->inputName() ?? $ref,
                $inputData?->variable?->typeRef,
            );
        }

        foreach ($service->inputDecisions as $ref) {
            $decision = $this->definitions->decisionById($ref);
            $parameters[] = new InvocableParameter(
                $decision?->resultName() ?? $ref,
                $decision?->variable?->typeRef,
            );
        }

        return $parameters;
    }
}
