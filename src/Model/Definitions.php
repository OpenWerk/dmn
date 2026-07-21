<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * The root of a parsed DMN model: an immutable, serializable value object.
 * Parse once, cache (e.g. with serialize()/unserialize()) and evaluate hot.
 */
final class Definitions
{
    /** @var array<string, Decision> */
    private readonly array $decisionsById;

    /** @var array<string, Decision> */
    private readonly array $decisionsByName;

    /** @var array<string, InputData> */
    private readonly array $inputDataById;

    /** @var array<string, BusinessKnowledgeModel> */
    private readonly array $knowledgeModelsById;

    /** @var array<string, DecisionService> */
    private readonly array $servicesById;

    /** @var array<string, DecisionService> */
    private readonly array $servicesByName;

    /** @var array<string, ItemDefinition> */
    private readonly array $itemDefinitionsByName;

    /**
     * @param list<Decision>               $decisions
     * @param list<InputData>              $inputData
     * @param list<ItemDefinition>         $itemDefinitions
     * @param list<BusinessKnowledgeModel> $businessKnowledgeModels
     * @param list<KnowledgeSource>        $knowledgeSources
     * @param list<DecisionService>        $decisionServices
     * @param list<Import>                 $imports
     * @param array<string, Definitions>   $importedModels resolved imports keyed by import name
     * @param list<Diagram>                $diagrams       DMNDI diagrams — read-only layout metadata
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $name,
        public readonly string $namespace,
        public readonly string $dmnVersion,
        public readonly array $decisions,
        public readonly array $inputData,
        public readonly array $itemDefinitions = [],
        public readonly array $businessKnowledgeModels = [],
        public readonly array $knowledgeSources = [],
        public readonly array $decisionServices = [],
        public readonly array $imports = [],
        public readonly array $importedModels = [],
        public readonly array $diagrams = [],
    ) {
        $decisionsById = [];
        $decisionsByName = [];

        foreach ($decisions as $decision) {
            $decisionsById[$decision->id] = $decision;
            $decisionsByName[$decision->name] = $decision;
        }

        $inputDataById = [];

        foreach ($inputData as $input) {
            $inputDataById[$input->id] = $input;
        }

        $knowledgeModelsById = [];

        foreach ($businessKnowledgeModels as $model) {
            $knowledgeModelsById[$model->id] = $model;
        }

        $servicesById = [];
        $servicesByName = [];

        foreach ($decisionServices as $service) {
            $servicesById[$service->id] = $service;
            $servicesByName[$service->name] = $service;
        }

        // First declaration wins, matching the linear-scan lookup this
        // index replaces.
        $itemDefinitionsByName = [];

        foreach ($itemDefinitions as $itemDefinition) {
            $itemDefinitionsByName[$itemDefinition->name] ??= $itemDefinition;
        }

        $this->decisionsById = $decisionsById;
        $this->decisionsByName = $decisionsByName;
        $this->inputDataById = $inputDataById;
        $this->knowledgeModelsById = $knowledgeModelsById;
        $this->servicesById = $servicesById;
        $this->servicesByName = $servicesByName;
        $this->itemDefinitionsByName = $itemDefinitionsByName;
    }

    public function decisionById(string $id): ?Decision
    {
        return $this->decisionsById[$id] ?? null;
    }

    public function decisionByName(string $name): ?Decision
    {
        return $this->decisionsByName[$name] ?? null;
    }

    /**
     * Looks a decision up by name first, then by id.
     */
    public function decision(string $nameOrId): ?Decision
    {
        return $this->decisionByName($nameOrId) ?? $this->decisionById($nameOrId);
    }

    public function inputDataById(string $id): ?InputData
    {
        return $this->inputDataById[$id] ?? null;
    }

    public function knowledgeModelById(string $id): ?BusinessKnowledgeModel
    {
        return $this->knowledgeModelsById[$id] ?? null;
    }

    public function itemDefinition(string $name): ?ItemDefinition
    {
        return $this->itemDefinitionsByName[$name] ?? null;
    }

    /**
     * Looks a decision service up by name first, then by id.
     */
    public function decisionService(string $nameOrId): ?DecisionService
    {
        return $this->servicesByName[$nameOrId] ?? $this->servicesById[$nameOrId] ?? null;
    }

    /**
     * @return list<string>
     */
    public function decisionNames(): array
    {
        return array_map(static fn(Decision $decision): string => $decision->name, $this->decisions);
    }

    public function importedModel(string $importName): ?Definitions
    {
        return $this->importedModels[$importName] ?? null;
    }

    /**
     * The import names under which the given model namespace is visible
     * (a namespace may be imported more than once under different names).
     *
     * @return list<string>
     */
    public function importNamesForNamespace(string $namespace): array
    {
        $names = [];

        foreach ($this->importedModels as $name => $imported) {
            if ($imported->namespace === $namespace) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }
}
