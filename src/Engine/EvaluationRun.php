<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Engine;

use OpenWerk\DecisionModelAndNotation\Diagnostics\Diagnostic;
use OpenWerk\DecisionModelAndNotation\Diagnostics\DiagnosticCollector;
use OpenWerk\DecisionModelAndNotation\Feel\Evaluator\FeelEvaluator;
use OpenWerk\DecisionModelAndNotation\Feel\Evaluator\Scope;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\FeelError;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ops;
use OpenWerk\DecisionModelAndNotation\Feel\Semantics\Ternary;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDate;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelDateTime;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelFunction;
use OpenWerk\DecisionModelAndNotation\Feel\Value\ZoneKind;
use OpenWerk\DecisionModelAndNotation\Model\BoxedConditional;
use OpenWerk\DecisionModelAndNotation\Model\BoxedContext;
use OpenWerk\DecisionModelAndNotation\Model\BoxedFilter;
use OpenWerk\DecisionModelAndNotation\Model\BoxedInvocation;
use OpenWerk\DecisionModelAndNotation\Model\BoxedIterator;
use OpenWerk\DecisionModelAndNotation\Model\BoxedList;
use OpenWerk\DecisionModelAndNotation\Model\BoxedRelation;
use OpenWerk\DecisionModelAndNotation\Model\BusinessKnowledgeModel;
use OpenWerk\DecisionModelAndNotation\Model\Decision;
use OpenWerk\DecisionModelAndNotation\Model\DecisionService;
use OpenWerk\DecisionModelAndNotation\Model\DecisionTable;
use OpenWerk\DecisionModelAndNotation\Model\Definitions;
use OpenWerk\DecisionModelAndNotation\Model\Expression;
use OpenWerk\DecisionModelAndNotation\Model\FunctionDefinition;
use OpenWerk\DecisionModelAndNotation\Model\LiteralExpression;

/**
 * One evaluation of a decision and its requirement graph: resolves
 * information requirements in dependency order (memoized), binds required
 * inputs and decision results into scope and evaluates the decision logic.
 *
 * A run is single-use internal state; the engine itself stays stateless and
 * side-effect free.
 *
 * @internal
 */
final class EvaluationRun
{
    private readonly DiagnosticCollector $diagnostics;

    private readonly FeelEvaluator $evaluator;

    /** One checker per run: its type and constraint caches stay warm. */
    private readonly TypeRefChecker $typeChecker;

    private ?DecisionTableEvaluator $tableEvaluator = null;

    /** The instant now()/today() report, shared with child runs, resolved lazily. */
    private readonly EvaluationClock $clock;

    private readonly Scope $clockScope;

    private readonly Scope $rootScope;

    /** @var array<string, mixed> */
    private array $memo = [];

    /** @var array<string, true> */
    private array $inProgress = [];

    /** @var array<string, self|null> child runs over imported models, per import name */
    private array $importRuns = [];

    /** @var list<MatchedRule> */
    private array $matchedRules = [];

    /**
     * @param array<string, mixed>                $inputs            FEEL values keyed by input data name
     * @param array<string, array<string, mixed>> $inputsByNamespace input data values for imported models,
     *                                                               keyed by model namespace, then name
     * @param \DateTimeImmutable|EvaluationClock|null $now           the instant now()/today() report; defaults
     *                                                               to the current UTC instant, resolved
     *                                                               lazily on first use (child runs receive
     *                                                               the parent's clock so the whole run
     *                                                               shares one instant)
     * @param ?array{service: string, decisions: array<string, true>, inputData: array<string, true>} $serviceVisibility
     *        when evaluating inside a decision service: the ids of the
     *        decisions and input data the service declares — requirements
     *        outside these sets error to null instead of resolving
     */
    public function __construct(
        private readonly Definitions $definitions,
        private readonly array $inputs,
        ?DiagnosticCollector $diagnostics = null,
        private readonly array $inputsByNamespace = [],
        \DateTimeImmutable|EvaluationClock|null $now = null,
        private readonly ?array $serviceVisibility = null,
        ?TypeRefChecker $typeChecker = null,
    ) {
        $this->diagnostics = $diagnostics ?? new DiagnosticCollector();
        $typeChecker ??= new TypeRefChecker($definitions);
        $this->typeChecker = $typeChecker;
        $this->evaluator = new FeelEvaluator(
            $this->diagnostics,
            static fn(string $name) => $typeChecker->resolveName($name),
        );

        // Caller inputs stay reachable as a fallback scope, so simple models
        // that skip explicit input data declarations still evaluate. now() and
        // today() sit below them, pinned to one instant for the whole run —
        // injectable for reproducible evaluations, shared with service and
        // import child runs.
        $clock = $now instanceof EvaluationClock ? $now : new EvaluationClock($now);
        $this->clock = $clock;
        $this->clockScope = new Scope([
            'now' => new FeelFunction([], static function () use ($clock): FeelDateTime {
                $instant = $clock->instant();
                $kind = str_contains($instant->getTimezone()->getName(), '/') ? ZoneKind::Zone : ZoneKind::Offset;

                return new FeelDateTime($instant, $kind);
            }),
            'today' => new FeelFunction([], static function () use ($clock): FeelDate {
                $instant = $clock->instant();

                return new FeelDate(
                    (int) $instant->format('Y'),
                    (int) $instant->format('n'),
                    (int) $instant->format('j'),
                );
            }),
        ]);
        $this->rootScope = new Scope($this->inputs, $this->clockScope);

        // Inputs may override decisions by name (the TCK's input decisions):
        // a supplied value short-circuits the decision's own logic.
        foreach ($this->inputs as $name => $value) {
            $decision = $definitions->decisionByName((string) $name);

            if ($decision !== null) {
                $this->memo[$decision->id] = $value;
            }
        }
    }

    public function run(Decision $decision): EvaluationResult
    {
        $value = $this->evaluateDecision($decision);

        return new EvaluationResult($decision->name, $value, $this->diagnostics->all(), $this->collectMatchedRules());
    }

    public function runService(DecisionService $service): EvaluationResult
    {
        $value = $this->evaluateService($service, $this->inputs);

        return new EvaluationResult($service->name, $value, $this->diagnostics->all(), $this->collectMatchedRules());
    }

    /**
     * This run's matched rules plus those of its import child runs, the
     * latter qualified by their import name ("hr.Seniority Level") — the
     * audit trail crosses the import boundary.
     *
     * @return list<MatchedRule>
     */
    private function collectMatchedRules(): array
    {
        $matched = $this->matchedRules;

        foreach ($this->importRuns as $importName => $run) {
            if ($run === null) {
                continue;
            }

            foreach ($run->collectMatchedRules() as $rule) {
                $matched[] = new MatchedRule(
                    $importName . '.' . $rule->decision,
                    $rule->ruleIndex,
                    $rule->ruleId,
                    $rule->tableId,
                );
            }
        }

        return $matched;
    }

    /**
     * Evaluates a decision service: input decisions and input data come from
     * the supplied inputs; output decisions (and their encapsulated
     * requirements) evaluate against them.
     *
     * @param array<string, mixed> $inputs FEEL values keyed by input decision / input data name
     */
    private function evaluateService(DecisionService $service, array $inputs): mixed
    {
        $checker = $this->typeChecker;

        // Supplied values must conform to the declared input types; input
        // decisions never evaluate their own logic inside the service.
        foreach ($service->inputData as $ref) {
            $inputData = $this->definitions->inputDataById($ref);

            if ($inputData === null) {
                continue;
            }

            $name = $inputData->inputName();

            if (!$this->conformingServiceInput($service, $name, $inputs, $inputData->variable?->typeRef, $checker)) {
                return null;
            }
        }

        $inputDecisionValues = [];

        foreach ($service->inputDecisions as $ref) {
            $decision = $this->definitions->decisionById($ref);

            if ($decision === null) {
                continue;
            }

            $name = $decision->resultName();

            if (!$this->conformingServiceInput($service, $name, $inputs, $decision->variable?->typeRef, $checker)) {
                return null;
            }

            $inputDecisionValues[$decision->id] = $inputs[$name] ?? null;
        }

        // Name resolution inside the service is restricted to what it
        // declares: input data, input decisions, encapsulated and output
        // decisions. Anything else errors instead of silently resolving.
        $visibility = [
            'service' => $service->name,
            'decisions' => array_fill_keys([
                ...$service->outputDecisions,
                ...$service->encapsulatedDecisions,
                ...$service->inputDecisions,
            ], true),
            'inputData' => array_fill_keys($service->inputData, true),
        ];

        $run = new self(
            $this->definitions,
            $inputs,
            $this->diagnostics,
            $this->inputsByNamespace,
            $this->clock,
            $visibility,
            $this->typeChecker,
        );

        foreach ($inputDecisionValues as $id => $value) {
            $run->memo[$id] = $value;
        }

        $outputs = [];

        foreach ($service->outputDecisions as $ref) {
            $decision = $this->definitions->decisionById($ref);

            if ($decision === null) {
                $this->diagnostics->error(
                    Diagnostic::DECISION_MISSING_INPUT,
                    sprintf('decision service output %s does not exist', var_export($ref, true)),
                );

                continue;
            }

            $outputs[$decision->resultName()] = $run->evaluateDecision($decision);
        }

        foreach ($run->collectMatchedRules() as $matchedRule) {
            $this->matchedRules[] = $matchedRule;
        }

        if ($outputs === []) {
            $this->diagnostics->error(
                Diagnostic::DECISION_NO_EXPRESSION,
                sprintf('decision service %s has no output decisions', var_export($service->name, true)),
            );

            return null;
        }

        $value = \count($outputs) === 1 ? reset($outputs) : $outputs;
        $typeRef = $service->variable?->typeRef;

        // A function-typed service variable constrains the invocation
        // result through its functionItem output type.
        $typeRef = $checker->functionOutputTypeRef($typeRef) ?? $typeRef;

        if ($typeRef !== null) {
            [$value, $conforms] = $checker->coerce($value, $typeRef);

            if (!$conforms) {
                $this->diagnostics->error(
                    Diagnostic::FEEL_ERROR,
                    sprintf(
                        'result of decision service %s does not conform to its declared type %s',
                        var_export($service->name, true),
                        var_export($typeRef, true),
                    ),
                );

                return null;
            }
        }

        return $value;
    }

    /**
     * Validates one supplied decision service input against its declared
     * type; a coerced value is written back into the supplied inputs.
     *
     * @param array<string, mixed> $inputs
     */
    private function conformingServiceInput(
        DecisionService $service,
        string $name,
        array &$inputs,
        ?string $typeRef,
        TypeRefChecker $checker,
    ): bool {
        if (!\array_key_exists($name, $inputs)) {
            return true;
        }

        [$value, $conforms] = $checker->coerce($inputs[$name], $typeRef);

        if (!$conforms) {
            $this->diagnostics->error(
                Diagnostic::FEEL_ERROR,
                sprintf(
                    'input %s of decision service %s does not conform to its declared type',
                    var_export($name, true),
                    var_export($service->name, true),
                ),
            );

            return false;
        }

        $inputs[$name] = $value;

        return true;
    }

    /**
     * Wraps a decision service as an invocable function; parameters are the
     * input data followed by the input decisions, in declaration order.
     * Arguments must conform to the declared input types.
     */
    private function serviceFunction(DecisionService $service): FeelFunction
    {
        $parameterNames = [];
        $parameterTypes = [];

        foreach ($service->inputData as $ref) {
            $inputData = $this->definitions->inputDataById($ref);
            $parameterNames[] = $inputData === null ? $ref : $inputData->inputName();
            $parameterTypes[] = $inputData?->variable?->typeRef;
        }

        foreach ($service->inputDecisions as $ref) {
            $decision = $this->definitions->decisionById($ref);
            $parameterNames[] = $decision === null ? $ref : $decision->resultName();
            $parameterTypes[] = $decision?->variable?->typeRef;
        }

        return new FeelFunction(
            $parameterNames,
            function (array $arguments) use ($service, $parameterNames, $parameterTypes): mixed {
                $inputs = [];
                $checker = $this->typeChecker;

                foreach ($parameterNames as $index => $name) {
                    [$argument, $conforms] = $checker->coerce(
                        $arguments[$index] ?? null,
                        $parameterTypes[$index] ?? null,
                    );

                    if (!$conforms) {
                        throw new FeelError(sprintf(
                            '%s(): argument %s does not conform to its declared type',
                            $service->name,
                            var_export($name, true),
                        ));
                    }

                    $inputs[$name] = $argument;
                }

                return $this->diagnostics->withDecision(
                    $service->name,
                    fn(): mixed => $this->evaluateService($service, $inputs),
                );
            },
        );
    }

    private function evaluateDecision(Decision $decision): mixed
    {
        if (\array_key_exists($decision->id, $this->memo)) {
            return $this->memo[$decision->id];
        }

        if (isset($this->inProgress[$decision->id])) {
            $this->diagnostics->error(
                Diagnostic::DECISION_CYCLE,
                sprintf('cyclic information requirement reached at decision %s', var_export($decision->name, true)),
            );

            return null;
        }

        $this->inProgress[$decision->id] = true;

        try {
            $value = $this->diagnostics->withDecision(
                $decision->name,
                fn(): mixed => $this->evaluateLogic($decision, $this->requirementScope($decision)),
            );
        } finally {
            unset($this->inProgress[$decision->id]);
        }

        $typeRef = $decision->variable?->typeRef;

        if ($typeRef !== null) {
            [$value, $conforms] = $this->typeChecker->coerce($value, $typeRef);

            if (!$conforms) {
                $this->diagnostics->withDecision($decision->name, function () use ($decision, $typeRef): void {
                    $this->diagnostics->error(
                        Diagnostic::FEEL_ERROR,
                        sprintf(
                            'result of decision %s does not conform to its declared type %s',
                            var_export($decision->name, true),
                            var_export($typeRef, true),
                        ),
                    );
                });
            }
        }

        $this->memo[$decision->id] = $value;

        return $value;
    }

    private function requirementScope(Decision $decision): Scope
    {
        $bindings = [];
        $importContexts = [];

        foreach ($decision->requiredInputs as $ref) {
            if (str_contains($ref, '#')) {
                $this->bindImported($ref, 'inputData', $importContexts, $decision->name);
                continue;
            }

            $inputData = $this->definitions->inputDataById($ref);

            if ($inputData === null) {
                $this->diagnostics->withDecision($decision->name, function () use ($ref): void {
                    $this->diagnostics->error(
                        Diagnostic::DECISION_MISSING_INPUT,
                        sprintf('required input data %s does not exist in the model', var_export($ref, true)),
                    );
                });

                continue;
            }

            $name = $inputData->inputName();

            if ($this->serviceVisibility !== null && !isset($this->serviceVisibility['inputData'][$ref])) {
                $this->diagnostics->withDecision($decision->name, function () use ($name): void {
                    $this->diagnostics->error(
                        Diagnostic::DECISION_MISSING_INPUT,
                        sprintf(
                            'input data %s is not visible inside decision service %s;'
                                . ' declare it as a service input',
                            var_export($name, true),
                            var_export($this->serviceVisibility['service'] ?? '', true),
                        ),
                    );
                });

                $bindings[$name] = null;
                continue;
            }

            if (!\array_key_exists($name, $this->inputs)) {
                $this->diagnostics->withDecision($decision->name, function () use ($name): void {
                    $this->diagnostics->warning(
                        Diagnostic::DECISION_MISSING_INPUT,
                        sprintf('input data %s was not supplied; it evaluates to null', var_export($name, true)),
                    );
                });
            }

            $bindings[$name] = $this->inputs[$name] ?? null;
        }

        foreach ($decision->requiredDecisions as $ref) {
            if (str_contains($ref, '#')) {
                $this->bindImported($ref, 'decision', $importContexts, $decision->name);
                continue;
            }

            $required = $this->definitions->decisionById($ref);

            if ($required === null) {
                $this->diagnostics->withDecision($decision->name, function () use ($ref): void {
                    $this->diagnostics->error(
                        Diagnostic::DECISION_MISSING_INPUT,
                        sprintf('required decision %s does not exist in the model', var_export($ref, true)),
                    );
                });

                continue;
            }

            if ($this->serviceVisibility !== null && !isset($this->serviceVisibility['decisions'][$ref])) {
                $this->diagnostics->withDecision($decision->name, function () use ($required): void {
                    $this->diagnostics->error(
                        Diagnostic::DECISION_MISSING_INPUT,
                        sprintf(
                            'decision %s is not visible inside decision service %s;'
                                . ' declare it as an encapsulated or input decision',
                            var_export($required->name, true),
                            var_export($this->serviceVisibility['service'] ?? '', true),
                        ),
                    );
                });

                $bindings[$required->resultName()] = null;
                continue;
            }

            $bindings[$required->resultName()] = $this->evaluateDecision($required);
        }

        foreach ($decision->requiredKnowledge as $ref) {
            if (str_contains($ref, '#')) {
                $this->bindImported($ref, 'knowledge', $importContexts, $decision->name);
                continue;
            }

            $knowledgeModel = $this->definitions->knowledgeModelById($ref);

            if ($knowledgeModel === null) {
                $service = $this->definitions->decisionService($ref);

                if ($service !== null) {
                    $bindings[$service->name] = $this->serviceFunction($service);
                    continue;
                }

                $this->diagnostics->withDecision($decision->name, function () use ($ref): void {
                    $this->diagnostics->error(
                        Diagnostic::DECISION_MISSING_INPUT,
                        sprintf('required business knowledge model %s does not exist', var_export($ref, true)),
                    );
                });

                continue;
            }

            $function = $this->knowledgeFunction($knowledgeModel, []);

            if ($function === null) {
                $this->diagnostics->withDecision($decision->name, function () use ($knowledgeModel): void {
                    $this->diagnostics->warning(
                        Diagnostic::DECISION_UNSUPPORTED_REQUIREMENT,
                        sprintf(
                            'business knowledge model %s has no evaluable logic; invocations yield null',
                            var_export($knowledgeModel->name, true),
                        ),
                    );
                });

                continue;
            }

            $bindings[$knowledgeModel->resultName()] = $function;
        }

        // Imported requirements group under their import name, which acts as
        // a context in FEEL ("myimport.Say Hello(...)").
        foreach ($importContexts as $importName => $context) {
            $bindings[$importName] = $context;
        }

        return $this->rootScope->child($bindings);
    }

    /**
     * Resolves one namespace-qualified requirement (`namespace#id`) and adds
     * its value under every import-name path that reaches that namespace —
     * directly ("hr") or chained through further import levels ("hr.base"),
     * nesting one context per level.
     *
     * @param 'decision'|'inputData'|'knowledge'   $kind
     * @param array<string, array<string, mixed>>  $importContexts
     */
    private function bindImported(string $ref, string $kind, array &$importContexts, string $forDecision): void
    {
        $hash = (int) strrpos($ref, '#');
        $namespace = substr($ref, 0, $hash);
        $id = substr($ref, $hash + 1);
        $paths = self::importPaths($namespace, $this->definitions, []);

        if ($paths === []) {
            $this->diagnostics->withDecision($forDecision, function () use ($ref): void {
                $this->diagnostics->error(
                    Diagnostic::DECISION_MISSING_INPUT,
                    sprintf('requirement %s does not match any resolved import', var_export($ref, true)),
                );
            });

            return;
        }

        foreach ($paths as $path) {
            $run = $this;

            foreach ($path as $segment) {
                $run = $run?->importRun($segment);
            }

            if ($run === null) {
                continue;
            }

            $imported = $run->definitions;
            $cursor = &self::importContextCursor($importContexts, $path);
            $bound = false;

            if ($kind === 'decision') {
                $required = $imported->decisionById($id);

                if ($required !== null) {
                    $cursor[$required->resultName()] = $run->evaluateDecision($required);
                    $bound = true;
                }
            } elseif ($kind === 'inputData') {
                $input = $imported->inputDataById($id);

                if ($input !== null) {
                    $cursor[$input->inputName()] = $run->inputs[$input->inputName()] ?? null;
                    $bound = true;
                }
            } else {
                $knowledgeModel = $imported->knowledgeModelById($id);
                $function = $knowledgeModel === null ? null : $run->knowledgeFunction($knowledgeModel, []);

                if ($knowledgeModel !== null && $function !== null) {
                    $cursor[$knowledgeModel->resultName()] = $function;
                    $bound = true;
                } elseif ($knowledgeModel === null) {
                    $service = $imported->decisionService($id);

                    if ($service !== null) {
                        $cursor[$service->name] = $run->serviceFunction($service);
                        $bound = true;
                    }
                }
            }

            unset($cursor);

            if (!$bound) {
                $this->diagnostics->withDecision($forDecision, function () use ($ref, $path): void {
                    $this->diagnostics->error(
                        Diagnostic::DECISION_MISSING_INPUT,
                        sprintf(
                            'requirement %s does not resolve inside import %s',
                            var_export($ref, true),
                            var_export(implode('.', $path), true),
                        ),
                    );
                });
            }
        }
    }

    /**
     * Import-name paths from the given model to the namespace: direct
     * imports win; otherwise the search chains through imported models
     * (guarded against import cycles).
     *
     * @param array<string, true> $seen model namespaces already walked
     *
     * @return list<non-empty-list<string>>
     */
    private static function importPaths(string $namespace, Definitions $definitions, array $seen): array
    {
        if (isset($seen[$definitions->namespace])) {
            return [];
        }

        $seen[$definitions->namespace] = true;
        $paths = [];

        foreach ($definitions->importNamesForNamespace($namespace) as $name) {
            $paths[] = [$name];
        }

        if ($paths !== []) {
            return $paths;
        }

        foreach ($definitions->importedModels as $importName => $imported) {
            foreach (self::importPaths($namespace, $imported, $seen) as $subPath) {
                $paths[] = [(string) $importName, ...$subPath];
            }
        }

        return $paths;
    }

    /**
     * The nested context to bind an import path's elements into, creating
     * one context level per path segment ("hr.base" -> ['hr' => ['base' =>
     * ...]]).
     *
     * @param array<string, array<string, mixed>> $importContexts
     * @param non-empty-list<string>              $path
     *
     * @return array<string, mixed>
     */
    private static function &importContextCursor(array &$importContexts, array $path): array
    {
        $cursor = &$importContexts;

        foreach ($path as $segment) {
            if (!isset($cursor[$segment]) || !\is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        // The by-reference descent loses the key type along the way; every
        // level only ever receives string keys.
        /** @var array<string, mixed> $cursor */
        return $cursor;
    }

    /**
     * The (memoized) child evaluation run over an imported model. Its inputs
     * come from the per-namespace input buckets.
     */
    private function importRun(string $importName): ?self
    {
        if (\array_key_exists($importName, $this->importRuns)) {
            return $this->importRuns[$importName];
        }

        $imported = $this->definitions->importedModel($importName);

        return $this->importRuns[$importName] = $imported === null ? null : new self(
            $imported,
            $this->inputsByNamespace[$imported->namespace] ?? [],
            $this->diagnostics,
            $this->inputsByNamespace,
            $this->clock,
        );
    }

    /**
     * Wraps a business knowledge model's encapsulated logic as an invocable
     * FEEL function. The body evaluates in an isolated scope of its formal
     * parameters plus its own knowledge requirements.
     *
     * @param array<string, true> $visited cycle guard over BKM ids
     */
    private function knowledgeFunction(BusinessKnowledgeModel $knowledgeModel, array $visited): ?FeelFunction
    {
        $body = $knowledgeModel->logic?->body;

        if ($body === null || isset($visited[$knowledgeModel->id])) {
            return null;
        }

        $visited[$knowledgeModel->id] = true;
        $parameterNames = [];

        foreach ($knowledgeModel->logic->parameters as $parameter) {
            $parameterNames[] = $parameter->name;
        }

        // A decision table without declared formal parameters implicitly takes
        // the root names of its input expressions as parameters (DMN 10.4).
        if ($parameterNames === [] && $body instanceof DecisionTable) {
            $parameterNames = $body->implicitParameterNames();
        }

        $functions = [];
        $importedFunctions = [];

        foreach ($knowledgeModel->requiredKnowledge as $ref) {
            if (str_contains($ref, '#')) {
                $hash = (int) strrpos($ref, '#');

                foreach ($this->definitions->importNamesForNamespace(substr($ref, 0, $hash)) as $importName) {
                    $run = $this->importRun($importName);
                    $required = $run?->definitions->knowledgeModelById(substr($ref, $hash + 1));
                    $function = $required === null ? null : $run->knowledgeFunction($required, []);

                    if ($required !== null && $function !== null) {
                        $importedFunctions[$importName][$required->resultName()] = $function;
                    }
                }

                continue;
            }

            $required = $this->definitions->knowledgeModelById($ref);
            $function = $required === null ? null : $this->knowledgeFunction($required, $visited);

            if ($required !== null && $function !== null) {
                $functions[$required->resultName()] = $function;
            }
        }

        $parameterTypes = [];

        foreach ($knowledgeModel->logic->parameters as $parameter) {
            $parameterTypes[] = $parameter->typeRef;
        }

        return new FeelFunction(
            $parameterNames,
            function (
                array $arguments,
            ) use (
                $body,
                $parameterNames,
                $parameterTypes,
                $functions,
                $importedFunctions,
                $knowledgeModel,
            ): mixed {
                $variables = $functions;

                // Imported knowledge groups under its import name (a context).
                foreach ($importedFunctions as $importName => $context) {
                    $variables[$importName] = $context;
                }

                $checker = $this->typeChecker;

                foreach ($parameterNames as $index => $name) {
                    [$argument, $conforms] = $checker->coerce(
                        $arguments[$index] ?? null,
                        $parameterTypes[$index] ?? null,
                    );

                    if (!$conforms) {
                        throw new FeelError(sprintf(
                            '%s(): argument %s does not conform to its declared type',
                            $knowledgeModel->name,
                            var_export($name, true),
                        ));
                    }

                    $variables[$name] = $argument;
                }

                $result = $this->diagnostics->withDecision(
                    $knowledgeModel->name,
                    fn(): mixed => $this->evaluateExpression(
                        $knowledgeModel->name,
                        $body,
                        $this->clockScope->child($variables),
                    ),
                );

                if ($body instanceof LiteralExpression && $body->typeRef !== null) {
                    [$result, $conforms] = $checker->coerce($result, $body->typeRef);

                    if (!$conforms) {
                        throw new FeelError(sprintf(
                            '%s(): result does not conform to its declared type %s',
                            $knowledgeModel->name,
                            var_export($body->typeRef, true),
                        ));
                    }
                }

                return $result;
            },
        );
    }

    private function evaluateLogic(Decision $decision, Scope $scope): mixed
    {
        $expression = $decision->expression;

        if ($expression === null) {
            $this->diagnostics->error(
                Diagnostic::DECISION_NO_EXPRESSION,
                sprintf('decision %s has no evaluable expression', var_export($decision->name, true)),
            );

            return null;
        }

        return $this->evaluateExpression($decision->name, $expression, $scope);
    }

    /**
     * Evaluates decision logic (literal expression or decision table) in the
     * given scope; matched table rules are recorded under $contextName.
     */
    private function evaluateExpression(string $contextName, Expression $expression, Scope $scope): mixed
    {
        if ($expression instanceof LiteralExpression) {
            if ($expression->ast === null) {
                $this->diagnostics->error(
                    Diagnostic::FEEL_INVALID_EXPRESSION,
                    sprintf('expression %s is not evaluable; it yields null', var_export($expression->text, true)),
                );

                return null;
            }

            return $this->evaluator->evaluate($expression->ast, $scope);
        }

        if ($expression instanceof DecisionTable) {
            $this->tableEvaluator ??= new DecisionTableEvaluator($this->evaluator, $this->diagnostics);
            [$value, $matches] = $this->tableEvaluator->evaluate($expression, $scope);

            foreach ($matches as [$ruleIndex, $ruleId]) {
                $this->matchedRules[] = new MatchedRule($contextName, $ruleIndex, $ruleId, $expression->id);
            }

            return $value;
        }

        if ($expression instanceof BoxedContext) {
            $context = [];

            foreach ($expression->entries as [$name, $entryExpression]) {
                $value = $entryExpression === null
                    ? null
                    : $this->evaluateExpression($contextName, $entryExpression, $scope->child($context));

                if ($name === null) {
                    // The final result entry is the whole context's value.
                    return $value;
                }

                $context[$name] = $value;
            }

            return $context;
        }

        if ($expression instanceof BoxedList) {
            $items = [];

            foreach ($expression->items as $itemExpression) {
                $items[] = $itemExpression === null
                    ? null
                    : $this->evaluateExpression($contextName, $itemExpression, $scope);
            }

            return $items;
        }

        if ($expression instanceof BoxedRelation) {
            $rows = [];

            foreach ($expression->rows as $row) {
                $context = [];

                foreach ($expression->columns as $index => $column) {
                    $cell = $row[$index] ?? null;
                    $context[$column] = $cell === null
                        ? null
                        : $this->evaluateExpression($contextName, $cell, $scope);
                }

                $rows[] = $context;
            }

            return $rows;
        }

        if ($expression instanceof BoxedInvocation) {
            return $this->evaluateInvocation($contextName, $expression, $scope);
        }

        if ($expression instanceof BoxedConditional) {
            $condition = $expression->if === null
                ? null
                : $this->evaluateExpression($contextName, $expression->if, $scope);

            // The conditional is strict: a condition that is not a boolean
            // (null included) errors to null instead of picking a branch.
            if (!\is_bool($condition)) {
                $this->diagnostics->error(
                    Diagnostic::FEEL_ERROR,
                    sprintf('%s: the conditional if expression must be a boolean', var_export($contextName, true)),
                );

                return null;
            }

            $branch = $condition ? $expression->then : $expression->else;

            return $branch === null ? null : $this->evaluateExpression($contextName, $branch, $scope);
        }

        if ($expression instanceof BoxedFilter) {
            return $this->evaluateBoxedFilter($contextName, $expression, $scope);
        }

        if ($expression instanceof BoxedIterator) {
            return $this->evaluateBoxedIterator($contextName, $expression, $scope);
        }

        if ($expression instanceof FunctionDefinition) {
            return $this->functionValue($contextName, $expression, $scope);
        }

        $this->diagnostics->error(
            Diagnostic::DECISION_NO_EXPRESSION,
            sprintf('%s uses an unsupported expression kind', var_export($contextName, true)),
        );

        return null;
    }

    private function evaluateBoxedFilter(string $contextName, BoxedFilter $filter, Scope $scope): mixed
    {
        if ($filter->in === null || $filter->match === null) {
            $this->diagnostics->error(
                Diagnostic::DECISION_NO_EXPRESSION,
                sprintf('%s has an incomplete boxed filter', var_export($contextName, true)),
            );

            return null;
        }

        $base = $this->evaluateExpression($contextName, $filter->in, $scope);

        if ($base === null) {
            return null;
        }

        $list = Ops::isList($base) ? array_values((array) $base) : [$base];
        $selected = [];

        foreach ($list as $item) {
            $bindings = [];

            if (Ops::isContext($item)) {
                foreach (Ops::contextToArray($item) as $key => $member) {
                    $bindings[(string) $key] = $member;
                }
            }

            // The whole element is reachable as `item` — unless the element
            // itself defines an `item` member, which takes precedence.
            if (!\array_key_exists('item', $bindings)) {
                $bindings['item'] = $item;
            }

            $outcome = $this->evaluateExpression($contextName, $filter->match, $scope->child($bindings));

            if ($outcome !== null && !\is_bool($outcome)) {
                $this->diagnostics->error(
                    Diagnostic::FEEL_ERROR,
                    sprintf('%s: the boxed filter match must be a boolean', var_export($contextName, true)),
                );

                return null;
            }

            if ($outcome === true) {
                $selected[] = $item;
            }
        }

        return $selected;
    }

    private function evaluateBoxedIterator(string $contextName, BoxedIterator $iterator, Scope $scope): mixed
    {
        if ($iterator->in === null || $iterator->body === null) {
            $this->diagnostics->error(
                Diagnostic::DECISION_NO_EXPRESSION,
                sprintf('%s has an incomplete boxed iterator', var_export($contextName, true)),
            );

            return null;
        }

        $domain = $this->evaluateExpression($contextName, $iterator->in, $scope);

        if ($domain === null) {
            return null;
        }

        $items = Ops::isList($domain) ? array_values((array) $domain) : [$domain];

        if ($iterator->kind === BoxedIterator::KIND_FOR) {
            $results = [];

            foreach ($items as $item) {
                $results[] = $this->evaluateExpression(
                    $contextName,
                    $iterator->body,
                    $scope->child([$iterator->iteratorVariable => $item, 'partial' => $results]),
                );
            }

            return $results;
        }

        $outcomes = [];

        foreach ($items as $item) {
            $outcome = $this->evaluateExpression(
                $contextName,
                $iterator->body,
                $scope->child([$iterator->iteratorVariable => $item]),
            );

            // A satisfies outcome must be a boolean; null stays null per
            // ternary logic, anything else errors the whole quantifier.
            if ($outcome !== null && !\is_bool($outcome)) {
                $this->diagnostics->error(
                    Diagnostic::FEEL_ERROR,
                    sprintf('%s: the satisfies expression must be a boolean', var_export($contextName, true)),
                );

                return null;
            }

            $outcomes[] = $outcome;
        }

        return $iterator->kind === BoxedIterator::KIND_EVERY
            ? Ternary::andAll(...$outcomes)
            : Ternary::orAll(...$outcomes);
    }

    /**
     * Evaluates a boxed function definition to an invocable function value
     * closing over the current scope.
     */
    private function functionValue(string $contextName, FunctionDefinition $definition, Scope $scope): ?FeelFunction
    {
        $body = $definition->body;

        if ($body === null) {
            $this->diagnostics->warning(
                Diagnostic::FEEL_ERROR,
                sprintf('%s defines a function without an evaluable body', var_export($contextName, true)),
            );

            return null;
        }

        $parameterNames = [];
        $parameterTypes = [];

        foreach ($definition->parameters as $parameter) {
            $parameterNames[] = $parameter->name;
            $parameterTypes[] = $parameter->typeRef;
        }

        return new FeelFunction(
            $parameterNames,
            function (array $arguments) use ($body, $parameterNames, $parameterTypes, $contextName, $scope): mixed {
                $checker = $this->typeChecker;
                $bindings = [];

                foreach ($parameterNames as $index => $name) {
                    [$argument, $conforms] = $checker->coerce($arguments[$index] ?? null, $parameterTypes[$index]);

                    if (!$conforms) {
                        throw new FeelError(sprintf(
                            '%s: argument %s does not conform to its declared type',
                            $contextName,
                            var_export($name, true),
                        ));
                    }

                    $bindings[$name] = $argument;
                }

                return $this->evaluateExpression($contextName, $body, $scope->child($bindings));
            },
        );
    }

    private function evaluateInvocation(string $contextName, BoxedInvocation $invocation, Scope $scope): mixed
    {
        $callee = $invocation->callee === null
            ? null
            : $this->evaluateExpression($contextName, $invocation->callee, $scope);

        if (!$callee instanceof FeelFunction) {
            $this->diagnostics->error(
                Diagnostic::FEEL_ERROR,
                sprintf('%s invokes something that is not a function', var_export($contextName, true)),
            );

            return null;
        }

        $named = [];

        foreach ($invocation->bindings as [$parameter, $bindingExpression]) {
            $named[$parameter] = $bindingExpression === null
                ? null
                : $this->evaluateExpression($contextName, $bindingExpression, $scope);
        }

        $arguments = [];

        foreach ($callee->parameterNames as $parameter) {
            $arguments[] = $named[$parameter] ?? null;
        }

        try {
            return $callee->invoke($arguments);
        } catch (FeelError $error) {
            $this->diagnostics->error(Diagnostic::FEEL_ERROR, $error->getMessage());

            return null;
        }
    }
}
