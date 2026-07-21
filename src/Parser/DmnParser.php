<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Parser;

use OpenWerk\DecisionModelAndNotation\Diagnostics\Severity;
use OpenWerk\DecisionModelAndNotation\Exception\FeelParseException;
use OpenWerk\DecisionModelAndNotation\Exception\ParseException;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Node;
use OpenWerk\DecisionModelAndNotation\Feel\Parser\FeelParser;
use OpenWerk\DecisionModelAndNotation\Feel\Parser\NameRegistry;
use OpenWerk\DecisionModelAndNotation\Model\BoxedConditional;
use OpenWerk\DecisionModelAndNotation\Model\BoxedContext;
use OpenWerk\DecisionModelAndNotation\Model\BoxedFilter;
use OpenWerk\DecisionModelAndNotation\Model\BoxedInvocation;
use OpenWerk\DecisionModelAndNotation\Model\BoxedIterator;
use OpenWerk\DecisionModelAndNotation\Model\BoxedList;
use OpenWerk\DecisionModelAndNotation\Model\BoxedRelation;
use OpenWerk\DecisionModelAndNotation\Model\BuiltinAggregator;
use OpenWerk\DecisionModelAndNotation\Model\BusinessKnowledgeModel;
use OpenWerk\DecisionModelAndNotation\Model\Decision;
use OpenWerk\DecisionModelAndNotation\Model\DecisionService;
use OpenWerk\DecisionModelAndNotation\Model\DecisionTable;
use OpenWerk\DecisionModelAndNotation\Model\Definitions;
use OpenWerk\DecisionModelAndNotation\Model\Diagram;
use OpenWerk\DecisionModelAndNotation\Model\DiagramBounds;
use OpenWerk\DecisionModelAndNotation\Model\DiagramEdge;
use OpenWerk\DecisionModelAndNotation\Model\DiagramShape;
use OpenWerk\DecisionModelAndNotation\Model\DiagramWaypoint;
use OpenWerk\DecisionModelAndNotation\Model\Expression;
use OpenWerk\DecisionModelAndNotation\Model\FunctionDefinition;
use OpenWerk\DecisionModelAndNotation\Model\HitPolicy;
use OpenWerk\DecisionModelAndNotation\Model\Import;
use OpenWerk\DecisionModelAndNotation\Model\InputClause;
use OpenWerk\DecisionModelAndNotation\Model\InputData;
use OpenWerk\DecisionModelAndNotation\Model\ItemDefinition;
use OpenWerk\DecisionModelAndNotation\Model\KnowledgeSource;
use OpenWerk\DecisionModelAndNotation\Model\LiteralExpression;
use OpenWerk\DecisionModelAndNotation\Model\OutputClause;
use OpenWerk\DecisionModelAndNotation\Model\Rule;
use OpenWerk\DecisionModelAndNotation\Model\UnaryTestsExpression;
use OpenWerk\DecisionModelAndNotation\Model\Variable;
use OpenWerk\DecisionModelAndNotation\Validation\SFeelChecker;
use OpenWerk\DecisionModelAndNotation\Validation\ValidationMessage;

/**
 * Parses DMN XML (1.1–1.5 model namespaces) into immutable model objects.
 *
 * Only unreadable documents throw ({@see ParseException}); every recoverable
 * model problem — unknown references, duplicate names, cyclic requirements,
 * FEEL syntax errors, structural table defects — is collected as a
 * position-aware {@see ValidationMessage}.
 *
 * DMNDI (diagram interchange) and any other foreign-namespace content is
 * ignored entirely.
 */
final class DmnParser
{
    private const array BOXED_EXPRESSIONS_SUPPORTED = [
        'decisionTable',
        'literalExpression',
        'context',
        'invocation',
        'relation',
        'list',
        'functionDefinition',
        'conditional',
        'filter',
        'for',
        'every',
        'some',
    ];

    /** @var list<ValidationMessage> */
    private array $messages = [];

    private string $modelNamespace = '';

    /** The model's default expression language (`<definitions expressionLanguage>`). */
    private ?string $defaultExpressionLanguage = null;

    private string $ownNamespace = '';

    /** @var array<string, Definitions> */
    private array $importedModels = [];

    private FeelParser $feelParser;

    private NameRegistry $nameRegistry;

    public function __construct(private readonly ?ImportResolver $importResolver = null)
    {
    }

    public function parse(string $xml): ParseResult
    {
        $this->messages = [];

        $root = $this->loadRoot($xml);

        $version = DmnNamespaces::versionOf((string) $root->namespaceURI);

        if ($version === null) {
            throw new ParseException(
                sprintf(
                    'unsupported root namespace %s - expected a DMN 1.1-1.5 model namespace',
                    var_export((string) $root->namespaceURI, true),
                ),
                $root->getLineNo(),
            );
        }

        $this->modelNamespace = (string) $root->namespaceURI;
        $this->ownNamespace = $this->attr($root, 'namespace') ?? '';
        $this->defaultExpressionLanguage = $this->attr($root, 'expressionLanguage');

        [$imports, $this->importedModels] = $this->resolveImports($root);

        $this->nameRegistry = $this->buildNameRegistry($root);
        $this->feelParser = new FeelParser($this->nameRegistry);

        $decisions = [];
        $inputData = [];
        $itemDefinitions = [];
        $knowledgeModels = [];
        $knowledgeSources = [];
        $decisionServices = [];
        $lines = [];

        foreach ($this->childElements($root) as $element) {
            switch ($element->localName) {
                case 'decision':
                    $decision = $this->parseDecision($element);
                    $decisions[] = $decision;
                    $lines[$decision->id] = $element->getLineNo();
                    break;

                case 'inputData':
                    $input = $this->parseInputData($element);
                    $inputData[] = $input;
                    $lines[$input->id] = $element->getLineNo();
                    break;

                case 'itemDefinition':
                    $itemDefinitions[] = $this->parseItemDefinition($element);
                    break;

                case 'businessKnowledgeModel':
                    $model = $this->parseKnowledgeModel($element);
                    $knowledgeModels[] = $model;
                    $lines[$model->id] = $element->getLineNo();
                    break;

                case 'knowledgeSource':
                    $source = new KnowledgeSource(
                        $this->elementId($element),
                        $this->attr($element, 'name') ?? $this->elementId($element),
                    );
                    $knowledgeSources[] = $source;
                    $lines[$source->id] = $element->getLineNo();
                    break;

                case 'decisionService':
                    $service = $this->parseDecisionService($element);
                    $decisionServices[] = $service;
                    $lines[$service->id] = $element->getLineNo();
                    break;

                default:
                    // extensionElements, artifacts, etc. — irrelevant to evaluation
                    break;
            }
        }

        $definitions = new Definitions(
            $this->attr($root, 'id'),
            $this->attr($root, 'name'),
            $this->attr($root, 'namespace') ?? '',
            $version,
            $decisions,
            $inputData,
            $itemDefinitions,
            $knowledgeModels,
            $knowledgeSources,
            $decisionServices,
            $imports,
            $this->importedModels,
            $this->parseDmndi($root),
        );

        $this->validateReferences($definitions, $lines);
        $this->validateUniqueNames($definitions, $lines);
        $this->validateAcyclicRequirements($definitions, $lines);

        return new ParseResult($definitions, $this->messages);
    }

    private function loadRoot(string $xml): \DOMElement
    {
        if (trim($xml) === '') {
            throw new ParseException('cannot parse an empty document');
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false) {
            $first = $errors[0] ?? null;

            throw new ParseException(
                'XML is not well-formed: ' . trim($first->message ?? 'unknown error'),
                $first?->line,
            );
        }

        $root = $document->documentElement;

        if ($root === null || $root->localName !== 'definitions') {
            throw new ParseException(
                sprintf('expected a DMN <definitions> root element, found <%s>', $root->localName ?? 'nothing'),
                $root?->getLineNo(),
            );
        }

        return $root;
    }

    /**
     * Collects every name that can be referenced from FEEL expressions, so
     * the parser can resolve multi-word names (`Risk Category`).
     */
    private function buildNameRegistry(\DOMElement $root): NameRegistry
    {
        $registry = NameRegistry::withBuiltins();

        foreach ($this->childElements($root) as $element) {
            $kind = $element->localName;

            if (
                $kind !== 'decision' && $kind !== 'inputData'
                && $kind !== 'businessKnowledgeModel' && $kind !== 'decisionService'
            ) {
                continue;
            }

            $name = $this->attr($element, 'name');

            if ($name !== null) {
                $registry->add($name);
            }

            foreach ($this->childElements($element, 'variable') as $variable) {
                $variableName = $this->attr($variable, 'name');

                if ($variableName !== null) {
                    $registry->add($variableName);
                }
            }
        }

        // Imported models: the import name resolves as a context, and the
        // imported element names must merge as multi-word path properties —
        // recursively, so chained references ("mid.lib.Base Level") lex.
        $seen = [];

        foreach ($this->importedModels as $importName => $imported) {
            $registry->add((string) $importName);
            self::registerImportedNames($registry, $imported, $seen);
        }

        return $registry;
    }

    /**
     * @param array<string, true> $seen model namespaces already registered
     */
    private static function registerImportedNames(NameRegistry $registry, Definitions $imported, array &$seen): void
    {
        if (isset($seen[$imported->namespace])) {
            return;
        }

        $seen[$imported->namespace] = true;

        foreach ($imported->decisions as $decision) {
            $registry->add($decision->name);
            $registry->add($decision->variable->name ?? $decision->name);
        }

        foreach ($imported->inputData as $input) {
            $registry->add($input->name);
            $registry->add($input->variable->name ?? $input->name);
        }

        foreach ($imported->businessKnowledgeModels as $model) {
            $registry->add($model->resultName());
        }

        foreach ($imported->decisionServices as $service) {
            $registry->add($service->name);
        }

        foreach ($imported->importedModels as $importName => $nested) {
            $registry->add((string) $importName);
            self::registerImportedNames($registry, $nested, $seen);
        }
    }

    private function parseDecision(\DOMElement $element): Decision
    {
        $id = $this->elementId($element);
        $name = $this->attr($element, 'name') ?? $id;

        $requiredDecisions = [];
        $requiredInputs = [];
        $requiredKnowledge = [];

        foreach ($this->childElements($element, 'informationRequirement') as $requirement) {
            foreach ($this->childElements($requirement, 'requiredDecision') as $required) {
                $ref = $this->localRef($required);

                if ($ref !== null) {
                    $requiredDecisions[] = $ref;
                }
            }

            foreach ($this->childElements($requirement, 'requiredInput') as $required) {
                $ref = $this->localRef($required);

                if ($ref !== null) {
                    $requiredInputs[] = $ref;
                }
            }
        }

        foreach ($this->childElements($element, 'knowledgeRequirement') as $requirement) {
            foreach ($this->childElements($requirement, 'requiredKnowledge') as $required) {
                $ref = $this->localRef($required);

                if ($ref !== null) {
                    $requiredKnowledge[] = $ref;
                }
            }
        }

        return new Decision(
            $id,
            $name,
            $this->parseVariable($element),
            $this->parseExpressionChild($element, $name),
            $requiredDecisions,
            $requiredInputs,
            $requiredKnowledge,
        );
    }

    private function parseExpressionChild(\DOMElement $element, string $decisionName): ?Expression
    {
        foreach ($this->childElements($element) as $child) {
            if (\in_array((string) $child->localName, self::BOXED_EXPRESSIONS_SUPPORTED, true)) {
                return $this->parseExpressionElement($child, $decisionName);
            }
        }

        return null;
    }

    /**
     * Parses one expression element (already known to be a supported kind).
     */
    private function parseExpressionElement(\DOMElement $element, string $context): ?Expression
    {
        return match ($element->localName) {
            'decisionTable' => $this->parseDecisionTable($element),
            'literalExpression' => $this->parseLiteralExpression($element),
            'context' => $this->parseBoxedContext($element, $context),
            'invocation' => $this->parseBoxedInvocation($element, $context),
            'relation' => $this->parseBoxedRelation($element, $context),
            'list' => $this->parseBoxedList($element, $context),
            'functionDefinition' => $this->parseFunctionDefinition($element, $context),
            'conditional' => new BoxedConditional(
                $this->attr($element, 'id'),
                $this->wrappedExpression($element, 'if', $context),
                $this->wrappedExpression($element, 'then', $context),
                $this->wrappedExpression($element, 'else', $context),
            ),
            'filter' => new BoxedFilter(
                $this->attr($element, 'id'),
                $this->wrappedExpression($element, 'in', $context),
                $this->wrappedExpression($element, 'match', $context),
            ),
            'for' => $this->parseBoxedIterator($element, BoxedIterator::KIND_FOR, 'return', $context),
            'some' => $this->parseBoxedIterator($element, BoxedIterator::KIND_SOME, 'satisfies', $context),
            'every' => $this->parseBoxedIterator($element, BoxedIterator::KIND_EVERY, 'satisfies', $context),
            default => null,
        };
    }

    /**
     * Parses the expression inside a wrapper child element (`<if>`, `<in>`,
     * `<return>`, ...).
     */
    private function wrappedExpression(\DOMElement $parent, string $wrapper, string $context): ?Expression
    {
        foreach ($this->childElements($parent, $wrapper) as $wrapperElement) {
            return $this->parseExpressionChild($wrapperElement, $context);
        }

        return null;
    }

    private function parseBoxedIterator(
        \DOMElement $element,
        string $kind,
        string $bodyWrapper,
        string $context,
    ): BoxedIterator {
        $variable = $this->attr($element, 'iteratorVariable') ?? 'item';
        $this->nameRegistry->add($variable);

        return new BoxedIterator(
            $this->attr($element, 'id'),
            $kind,
            $variable,
            $this->wrappedExpression($element, 'in', $context),
            $this->wrappedExpression($element, $bodyWrapper, $context),
        );
    }

    private function parseFunctionDefinition(\DOMElement $element, string $context): FunctionDefinition
    {
        $parameters = [];

        foreach ($this->childElements($element, 'formalParameter') as $index => $parameterElement) {
            $name = $this->attr($parameterElement, 'name') ?? 'parameter ' . ($index + 1);
            $this->nameRegistry->add($name);
            $parameters[] = new Variable(
                $this->attr($parameterElement, 'id'),
                $name,
                $this->attr($parameterElement, 'typeRef'),
            );
        }

        if ($this->attr($element, 'kind') === 'PMML' || $this->attr($element, 'kind') === 'Java') {
            $this->message(
                Severity::Warning,
                'external (Java/PMML) function definitions are not supported; the function is null',
                $element,
            );

            return new FunctionDefinition($this->attr($element, 'id'), $parameters, null);
        }

        return new FunctionDefinition(
            $this->attr($element, 'id'),
            $parameters,
            $this->parseExpressionChild($element, $context),
        );
    }

    private function parseBoxedContext(\DOMElement $element, string $context): BoxedContext
    {
        $entries = [];

        foreach ($this->childElements($element, 'contextEntry') as $entryElement) {
            $name = null;

            foreach ($this->childElements($entryElement, 'variable') as $variableElement) {
                $name = $this->attr($variableElement, 'name');
            }

            if ($name !== null) {
                // Later entries (and the result entry) reference this entry
                // by name — including multi-word names.
                $this->nameRegistry->add($name);
            }

            $entries[] = [$name, $this->parseExpressionChild($entryElement, $context)];
        }

        return new BoxedContext($this->attr($element, 'id'), $entries);
    }

    private function parseBoxedInvocation(\DOMElement $element, string $context): BoxedInvocation
    {
        $callee = $this->parseExpressionChild($element, $context);
        $bindings = [];

        foreach ($this->childElements($element, 'binding') as $bindingElement) {
            $parameter = null;

            foreach ($this->childElements($bindingElement, 'parameter') as $parameterElement) {
                $parameter = $this->attr($parameterElement, 'name');
            }

            if ($parameter === null) {
                $this->message(Severity::Error, 'invocation binding without a parameter name', $bindingElement);
                continue;
            }

            $bindings[] = [$parameter, $this->parseExpressionChild($bindingElement, $context)];
        }

        return new BoxedInvocation($this->attr($element, 'id'), $callee, $bindings);
    }

    private function parseBoxedRelation(\DOMElement $element, string $context): BoxedRelation
    {
        $columns = [];

        foreach ($this->childElements($element, 'column') as $index => $columnElement) {
            $columns[] = $this->attr($columnElement, 'name') ?? 'column ' . ($index + 1);
        }

        $rows = [];

        foreach ($this->childElements($element, 'row') as $rowElement) {
            $cells = [];

            foreach ($this->childElements($rowElement) as $cellElement) {
                if (\in_array($cellElement->localName, self::BOXED_EXPRESSIONS_SUPPORTED, true)) {
                    $cells[] = $this->parseExpressionElement($cellElement, $context);
                }
            }

            $rows[] = $cells;
        }

        return new BoxedRelation($this->attr($element, 'id'), $columns, $rows);
    }

    private function parseBoxedList(\DOMElement $element, string $context): BoxedList
    {
        $items = [];

        foreach ($this->childElements($element) as $itemElement) {
            if (\in_array($itemElement->localName, self::BOXED_EXPRESSIONS_SUPPORTED, true)) {
                $items[] = $this->parseExpressionElement($itemElement, $context);
            }
        }

        return new BoxedList($this->attr($element, 'id'), $items);
    }

    private function parseLiteralExpression(\DOMElement $element): LiteralExpression
    {
        $language = $this->attr($element, 'expressionLanguage');
        $text = $this->textOf($element);

        $typeRef = $this->attr($element, 'typeRef');

        if ($text === null || $text === '') {
            $this->message(Severity::Warning, 'literal expression without text evaluates to null', $element);

            return new LiteralExpression($this->attr($element, 'id'), '', null, $language, $typeRef);
        }

        if ($language !== null && !self::isFeelLanguage($language)) {
            $this->message(
                Severity::Warning,
                sprintf(
                    'unsupported expression language %s; expression evaluates to null',
                    var_export($language, true),
                ),
                $element,
            );

            return new LiteralExpression($this->attr($element, 'id'), $text, null, $language, $typeRef);
        }

        $ast = null;

        try {
            $ast = $this->feelParser->parseExpression($text);
        } catch (FeelParseException $exception) {
            $this->message(Severity::Error, $exception->getMessage(), $element);
        }

        $this->checkSFeel($ast, $language, $element);

        return new LiteralExpression($this->attr($element, 'id'), $text, $ast, $language, $typeRef);
    }

    private function parseUnaryTests(\DOMElement $element, bool $emptyMeansAny): ?UnaryTestsExpression
    {
        $text = $this->textOf($element);

        if ($text === null || $text === '') {
            if (!$emptyMeansAny) {
                $this->message(Severity::Warning, 'empty unary tests are ignored', $element);

                return null;
            }

            $this->message(Severity::Warning, 'empty input entry is treated as irrelevant ("-")', $element);
            $text = '-';
        }

        $ast = null;

        try {
            $ast = $this->feelParser->parseUnaryTests($text);
        } catch (FeelParseException $exception) {
            $this->message(Severity::Error, $exception->getMessage(), $element);
        }

        $this->checkSFeel($ast, $this->attr($element, 'expressionLanguage'), $element);

        return new UnaryTestsExpression($this->attr($element, 'id'), $text, $ast);
    }

    private function parseDecisionTable(\DOMElement $element): DecisionTable
    {
        $hitPolicyText = $this->attr($element, 'hitPolicy') ?? 'UNIQUE';
        $hitPolicy = HitPolicy::tryFrom($hitPolicyText);

        if ($hitPolicy === null) {
            $this->message(
                Severity::Error,
                sprintf('unknown hit policy %s; falling back to UNIQUE', var_export($hitPolicyText, true)),
                $element,
            );
            $hitPolicy = HitPolicy::Unique;
        }

        $aggregation = null;
        $aggregationText = $this->attr($element, 'aggregation');

        if ($aggregationText !== null && $aggregationText !== '') {
            $aggregation = BuiltinAggregator::tryFrom($aggregationText);

            if ($aggregation === null) {
                $this->message(
                    Severity::Error,
                    sprintf('unknown aggregation %s; ignoring it', var_export($aggregationText, true)),
                    $element,
                );
            } elseif ($hitPolicy !== HitPolicy::Collect) {
                $this->message(
                    Severity::Warning,
                    sprintf('aggregation %s is only meaningful with the COLLECT hit policy', $aggregationText),
                    $element,
                );
                $aggregation = null;
            }
        }

        $inputs = [];

        foreach ($this->childElements($element, 'input') as $inputElement) {
            $inputs[] = $this->parseInputClause($inputElement);
        }

        $outputs = [];

        foreach ($this->childElements($element, 'output') as $outputElement) {
            $outputs[] = $this->parseOutputClause($outputElement);
        }

        if ($outputs === []) {
            $this->message(Severity::Error, 'decision table has no output clause', $element);
        }

        if (\count($outputs) > 1 && $aggregation !== null) {
            $this->message(
                Severity::Error,
                'COLLECT aggregation requires a single output clause',
                $element,
            );
        }

        if (\count($outputs) > 1) {
            foreach ($outputs as $index => $output) {
                if ($output->name === null && $output->label === null) {
                    $this->message(
                        Severity::Warning,
                        sprintf(
                            'output clause %d of a multi-output table has neither name nor label; '
                            . 'its result key defaults to %s',
                            $index + 1,
                            var_export($output->resultKey($index), true),
                        ),
                        $element,
                    );
                }
            }
        }

        if (
            ($hitPolicy === HitPolicy::Priority || $hitPolicy === HitPolicy::OutputOrder)
            && array_any($outputs, static fn(OutputClause $output): bool => $output->outputValues === null)
        ) {
            $this->message(
                Severity::Warning,
                sprintf('hit policy %s requires output values on every output clause', $hitPolicy->value),
                $element,
            );
        }

        $rules = [];
        $ruleIndex = 0;

        foreach ($this->childElements($element, 'rule') as $ruleElement) {
            $ruleIndex++;
            $rule = $this->parseRule($ruleElement);

            if (\count($rule->inputEntries) !== \count($inputs)) {
                $this->message(
                    Severity::Error,
                    sprintf(
                        'rule %d has %d input entries but the table declares %d inputs',
                        $ruleIndex,
                        \count($rule->inputEntries),
                        \count($inputs),
                    ),
                    $ruleElement,
                );
            }

            if (\count($rule->outputEntries) !== \count($outputs)) {
                $this->message(
                    Severity::Error,
                    sprintf(
                        'rule %d has %d output entries but the table declares %d outputs',
                        $ruleIndex,
                        \count($rule->outputEntries),
                        \count($outputs),
                    ),
                    $ruleElement,
                );
            }

            $rules[] = $rule;
        }

        return new DecisionTable(
            $this->attr($element, 'id'),
            $hitPolicy,
            $aggregation,
            $inputs,
            $outputs,
            $rules,
        );
    }

    private function parseInputClause(\DOMElement $element): InputClause
    {
        $expression = null;
        $inputValues = null;

        foreach ($this->childElements($element, 'inputExpression') as $expressionElement) {
            $expression = $this->parseLiteralExpression($expressionElement);
        }

        foreach ($this->childElements($element, 'inputValues') as $valuesElement) {
            $inputValues = $this->parseUnaryTests($valuesElement, false);
        }

        if ($expression === null) {
            $this->message(Severity::Error, 'decision table input without an input expression', $element);
            $expression = new LiteralExpression(null, '', null);
        }

        return new InputClause(
            $this->attr($element, 'id'),
            $this->attr($element, 'label'),
            $expression,
            $this->attr($element, 'typeRef') ?? $this->attrOf($element, 'inputExpression', 'typeRef'),
            $inputValues,
        );
    }

    private function parseOutputClause(\DOMElement $element): OutputClause
    {
        $outputValues = null;
        $defaultOutputEntry = null;

        foreach ($this->childElements($element, 'outputValues') as $valuesElement) {
            $outputValues = $this->parseUnaryTests($valuesElement, false);
        }

        foreach ($this->childElements($element, 'defaultOutputEntry') as $defaultElement) {
            $defaultOutputEntry = $this->parseLiteralExpression($defaultElement);
        }

        return new OutputClause(
            $this->attr($element, 'id'),
            $this->attr($element, 'name'),
            $this->attr($element, 'label'),
            $this->attr($element, 'typeRef'),
            $outputValues,
            $defaultOutputEntry,
        );
    }

    private function parseRule(\DOMElement $element): Rule
    {
        $inputEntries = [];
        $outputEntries = [];
        $description = null;

        foreach ($this->childElements($element, 'description') as $descriptionElement) {
            $description = trim($descriptionElement->textContent);
        }

        foreach ($this->childElements($element, 'inputEntry') as $entryElement) {
            $entry = $this->parseUnaryTests($entryElement, true);

            if ($entry !== null) {
                $inputEntries[] = $entry;
            }
        }

        foreach ($this->childElements($element, 'outputEntry') as $entryElement) {
            $outputEntries[] = $this->parseLiteralExpression($entryElement);
        }

        return new Rule($this->attr($element, 'id'), $inputEntries, $outputEntries, $description);
    }

    private function parseInputData(\DOMElement $element): InputData
    {
        return new InputData(
            $this->elementId($element),
            $this->attr($element, 'name') ?? $this->elementId($element),
            $this->parseVariable($element),
        );
    }

    private function parseVariable(\DOMElement $element): ?Variable
    {
        foreach ($this->childElements($element, 'variable') as $variableElement) {
            $name = $this->attr($variableElement, 'name') ?? $this->attr($element, 'name');

            if ($name === null) {
                return null;
            }

            return new Variable(
                $this->attr($variableElement, 'id'),
                $name,
                $this->attr($variableElement, 'typeRef'),
            );
        }

        return null;
    }

    private function parseItemDefinition(\DOMElement $element): ItemDefinition
    {
        $components = [];

        foreach ($this->childElements($element, 'itemComponent') as $componentElement) {
            $components[] = $this->parseItemDefinition($componentElement);
        }

        $typeRef = null;

        foreach ($this->childElements($element, 'typeRef') as $typeRefElement) {
            $typeRef = trim($typeRefElement->textContent);
        }

        $allowedValues = null;

        foreach ($this->childElements($element, 'allowedValues') as $allowedElement) {
            $allowedValues = $this->parseUnaryTests($allowedElement, false);
        }

        $functionOutputTypeRef = null;

        foreach ($this->childElements($element, 'functionItem') as $functionElement) {
            $functionOutputTypeRef = $this->attr($functionElement, 'outputTypeRef');
        }

        return new ItemDefinition(
            $this->attr($element, 'id'),
            $this->attr($element, 'name') ?? $this->elementId($element),
            $typeRef,
            $this->attr($element, 'isCollection') === 'true',
            $components,
            $allowedValues,
            $functionOutputTypeRef,
        );
    }

    private function parseKnowledgeModel(\DOMElement $element): BusinessKnowledgeModel
    {
        $name = $this->attr($element, 'name') ?? $this->elementId($element);
        $logic = null;

        foreach ($this->childElements($element, 'encapsulatedLogic') as $logicElement) {
            $logic = $this->parseFunctionDefinition($logicElement, $name);
        }

        $requiredKnowledge = [];

        foreach ($this->childElements($element, 'knowledgeRequirement') as $requirement) {
            foreach ($this->childElements($requirement, 'requiredKnowledge') as $required) {
                $ref = $this->localRef($required);

                if ($ref !== null) {
                    $requiredKnowledge[] = $ref;
                }
            }
        }

        return new BusinessKnowledgeModel(
            $this->elementId($element),
            $name,
            $this->parseVariable($element),
            $requiredKnowledge,
            $logic,
        );
    }

    private function parseDecisionService(\DOMElement $element): DecisionService
    {
        $collect = function (string $kind) use ($element): array {
            $refs = [];

            foreach ($this->childElements($element, $kind) as $refElement) {
                $ref = $this->localRef($refElement);

                if ($ref !== null) {
                    $refs[] = $ref;
                }
            }

            return $refs;
        };

        return new DecisionService(
            $this->elementId($element),
            $this->attr($element, 'name') ?? $this->elementId($element),
            $collect('outputDecision'),
            $collect('encapsulatedDecision'),
            $collect('inputDecision'),
            $collect('inputData'),
            $this->parseVariable($element),
        );
    }

    /**
     * Parses the model's `<import>` elements and resolves DMN model imports
     * through the configured resolver.
     *
     * @return array{list<Import>, array<string, Definitions>}
     */
    private function resolveImports(\DOMElement $root): array
    {
        $imports = [];
        $importedModels = [];

        foreach ($this->childElements($root, 'import') as $element) {
            $import = new Import(
                $this->attr($element, 'namespace') ?? '',
                $this->attr($element, 'name'),
                $this->attr($element, 'importType'),
                $this->attr($element, 'locationURI'),
            );
            $imports[] = $import;

            if ($import->importType !== null && !self::isDmnModelImportType($import->importType)) {
                // XSD/PMML and other non-DMN imports are legitimate model
                // metadata — surfaced via imports(), never resolved.
                $this->message(
                    Severity::Info,
                    sprintf(
                        'import of type %s is metadata only; DMN model imports resolve',
                        var_export($import->importType, true),
                    ),
                    $element,
                );

                continue;
            }

            if ($import->name === null || $import->name === '') {
                $this->message(
                    Severity::Warning,
                    'an unnamed model import cannot be referenced; skipping it',
                    $element,
                );

                continue;
            }

            if ($this->importResolver === null) {
                $this->message(
                    Severity::Warning,
                    sprintf(
                        'import %s is not resolved: parsing from a string has no import resolver'
                        . ' (use Dmn::fromFile() or pass an ImportResolver)',
                        var_export($import->name, true),
                    ),
                    $element,
                );

                continue;
            }

            $resolved = $this->importResolver->resolve($import->namespace);

            foreach ($this->importResolver->drainMessages() as $message) {
                $this->messages[] = $message;
            }

            if ($resolved === null) {
                $this->message(
                    Severity::Warning,
                    sprintf(
                        'imported model with namespace %s was not found; '
                        . 'references into import %s will not resolve',
                        var_export($import->namespace, true),
                        var_export($import->name, true),
                    ),
                    $element,
                );

                continue;
            }

            $importedModels[$import->name] = $resolved;
        }

        return [$imports, $importedModels];
    }

    private static function isDmnModelImportType(string $importType): bool
    {
        return preg_match('~^https?://www\.omg\.org/spec/DMN/\d+/MODEL/?$~i', $importType) === 1;
    }

    /**
     * Whether the namespace belongs to a (transitively) imported model —
     * chained references keep their namespace#id form for the evaluator.
     *
     * @param array<string, Definitions> $importedModels
     * @param array<string, true>        $seen
     */
    private static function importedNamespaceKnown(
        string $namespace,
        array $importedModels,
        array $seen = [],
    ): bool {
        foreach ($importedModels as $imported) {
            if (isset($seen[$imported->namespace])) {
                continue;
            }

            $seen[$imported->namespace] = true;

            if (
                $imported->namespace === $namespace
                || self::importedNamespaceKnown($namespace, $imported->importedModels, $seen)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int> $lines
     */
    private function validateReferences(Definitions $definitions, array $lines): void
    {
        foreach ($definitions->decisions as $decision) {
            $line = $lines[$decision->id] ?? null;

            foreach ($decision->requiredDecisions as $ref) {
                if (!$this->refResolves($definitions, $ref, 'decision')) {
                    $this->messages[] = new ValidationMessage(
                        Severity::Error,
                        sprintf(
                            'decision %s requires unknown decision %s',
                            var_export($decision->name, true),
                            var_export($ref, true),
                        ),
                        $decision->id,
                        $line,
                    );
                }
            }

            foreach ($decision->requiredInputs as $ref) {
                if (!$this->refResolves($definitions, $ref, 'inputData')) {
                    $this->messages[] = new ValidationMessage(
                        Severity::Error,
                        sprintf(
                            'decision %s requires unknown input data %s',
                            var_export($decision->name, true),
                            var_export($ref, true),
                        ),
                        $decision->id,
                        $line,
                    );
                }
            }

            foreach ($decision->requiredKnowledge as $ref) {
                if (!$this->refResolves($definitions, $ref, 'knowledge')) {
                    $this->messages[] = new ValidationMessage(
                        Severity::Error,
                        sprintf(
                            'decision %s requires unknown business knowledge model %s',
                            var_export($decision->name, true),
                            var_export($ref, true),
                        ),
                        $decision->id,
                        $line,
                    );
                }
            }
        }
    }

    /**
     * Whether a (possibly namespace-qualified) requirement reference points
     * at an existing element of the right kind.
     */
    private function refResolves(Definitions $definitions, string $ref, string $kind): bool
    {
        $hash = strrpos($ref, '#');

        if ($hash !== false) {
            return $this->importedRefResolves($definitions, substr($ref, 0, $hash), substr($ref, $hash + 1), $kind, []);
        }

        return match ($kind) {
            'decision' => $definitions->decisionById($ref) !== null,
            'inputData' => $definitions->inputDataById($ref) !== null,
            default => $definitions->knowledgeModelById($ref) !== null
                || $definitions->decisionService($ref) !== null,
        };
    }

    /**
     * Whether a namespace-qualified reference resolves in a (transitively)
     * imported model, chained through any number of import levels.
     *
     * @param array<string, true> $seen model namespaces already walked
     */
    private function importedRefResolves(
        Definitions $definitions,
        string $namespace,
        string $id,
        string $kind,
        array $seen,
    ): bool {
        if (isset($seen[$definitions->namespace])) {
            return false;
        }

        $seen[$definitions->namespace] = true;

        foreach ($definitions->importedModels as $imported) {
            if ($imported->namespace === $namespace && $this->refResolves($imported, $id, $kind)) {
                return true;
            }
        }

        foreach ($definitions->importedModels as $imported) {
            if ($this->importedRefResolves($imported, $namespace, $id, $kind, $seen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int> $lines
     */
    private function validateUniqueNames(Definitions $definitions, array $lines): void
    {
        $byName = [];

        foreach ($definitions->decisions as $decision) {
            $byName[$decision->name][] = $decision->id;
        }

        foreach ($definitions->inputData as $input) {
            $byName[$input->name][] = $input->id;
        }

        foreach ($definitions->businessKnowledgeModels as $model) {
            $byName[$model->name][] = $model->id;
        }

        foreach ($definitions->decisionServices as $service) {
            $byName[$service->name][] = $service->id;
        }

        foreach ($byName as $name => $ids) {
            if (\count($ids) < 2) {
                continue;
            }

            $this->messages[] = new ValidationMessage(
                Severity::Error,
                sprintf(
                    'duplicate name %s shared by elements %s',
                    var_export((string) $name, true),
                    implode(', ', $ids),
                ),
                $ids[1],
                $lines[$ids[1]] ?? null,
            );
        }
    }

    /**
     * @param array<string, int> $lines
     */
    private function validateAcyclicRequirements(Definitions $definitions, array $lines): void
    {
        $states = [];

        foreach ($definitions->decisions as $decision) {
            $this->visitRequirements($decision, [], $states, $definitions, $lines);
        }
    }

    /**
     * Depth-first search over the required-decision edges; a back edge is a
     * cyclic information requirement.
     *
     * @param list<string>                     $stack
     * @param array<string, 'visiting'|'done'> $states
     * @param array<string, int>               $lines
     */
    private function visitRequirements(
        Decision $decision,
        array $stack,
        array &$states,
        Definitions $definitions,
        array $lines,
    ): void {
        $state = $states[$decision->id] ?? null;

        if ($state === 'done') {
            return;
        }

        if ($state === 'visiting') {
            $start = array_search($decision->id, $stack, true);
            $cycleIds = [...\array_slice($stack, (int) $start), $decision->id];
            $cycleNames = array_map(
                static fn(string $id): string => $definitions->decisionById($id)->name ?? $id,
                $cycleIds,
            );

            $this->messages[] = new ValidationMessage(
                Severity::Error,
                'cyclic information requirement: ' . implode(' -> ', $cycleNames),
                $decision->id,
                $lines[$decision->id] ?? null,
            );

            return;
        }

        $states[$decision->id] = 'visiting';

        foreach ($decision->requiredDecisions as $ref) {
            $required = $definitions->decisionById($ref);

            if ($required !== null) {
                $this->visitRequirements($required, [...$stack, $decision->id], $states, $definitions, $lines);
            }
        }

        $states[$decision->id] = 'done';
    }

    /**
     * Resolves a DMN href. `#id` (and `own-namespace#id`) yield the local
     * id; a reference into a resolved import keeps its `namespace#id` form
     * for the evaluator to route; anything else is reported and skipped.
     */
    private function localRef(\DOMElement $element): ?string
    {
        $href = $this->attr($element, 'href');

        if ($href === null || $href === '') {
            $this->message(Severity::Error, sprintf('<%s> without href', (string) $element->localName), $element);

            return null;
        }

        if (str_starts_with($href, '#')) {
            return substr($href, 1);
        }

        $hash = strrpos($href, '#');

        if ($hash !== false) {
            $namespace = substr($href, 0, $hash);
            $id = substr($href, $hash + 1);

            if ($namespace === $this->ownNamespace) {
                return $id;
            }

            if (self::importedNamespaceKnown($namespace, $this->importedModels)) {
                return $namespace . '#' . $id;
            }
        }

        $this->message(
            Severity::Warning,
            sprintf(
                'external reference %s does not match the model or a resolved import; skipping it',
                var_export($href, true),
            ),
            $element,
        );

        return null;
    }

    /**
     * Parses the model's DMNDI block into read-only diagram metadata.
     * DMNDI, DC and DI elements live in version-specific companion
     * namespaces, so they match by local name only.
     *
     * @return list<Diagram>
     */
    private function parseDmndi(\DOMElement $root): array
    {
        $diagrams = [];

        foreach ($this->foreignChildren($root, 'DMNDI') as $dmndi) {
            foreach ($this->foreignChildren($dmndi, 'DMNDiagram') as $diagramElement) {
                $diagrams[] = $this->parseDiagram($diagramElement);
            }
        }

        return $diagrams;
    }

    private function parseDiagram(\DOMElement $element): Diagram
    {
        $shapes = [];
        $edges = [];
        $width = null;
        $height = null;

        foreach ($this->foreignChildren($element) as $child) {
            switch ($child->localName) {
                case 'DMNShape':
                    $shapes[] = new DiagramShape(
                        $this->attr($child, 'id'),
                        $this->attr($child, 'dmnElementRef'),
                        $this->parseDiagramBounds($child),
                        $this->attr($child, 'isCollapsed') === 'true',
                    );
                    break;

                case 'DMNEdge':
                    $waypoints = [];

                    foreach ($this->foreignChildren($child, 'waypoint') as $waypointElement) {
                        $x = $this->floatAttr($waypointElement, 'x');
                        $y = $this->floatAttr($waypointElement, 'y');

                        if ($x !== null && $y !== null) {
                            $waypoints[] = new DiagramWaypoint($x, $y);
                        }
                    }

                    $edges[] = new DiagramEdge(
                        $this->attr($child, 'id'),
                        $this->attr($child, 'dmnElementRef'),
                        $waypoints,
                    );
                    break;

                case 'Size':
                    $width = $this->floatAttr($child, 'width');
                    $height = $this->floatAttr($child, 'height');
                    break;
            }
        }

        return new Diagram(
            $this->attr($element, 'id'),
            $this->attr($element, 'name'),
            $width,
            $height,
            $shapes,
            $edges,
        );
    }

    private function parseDiagramBounds(\DOMElement $shape): ?DiagramBounds
    {
        foreach ($this->foreignChildren($shape, 'Bounds') as $bounds) {
            $x = $this->floatAttr($bounds, 'x');
            $y = $this->floatAttr($bounds, 'y');
            $width = $this->floatAttr($bounds, 'width');
            $height = $this->floatAttr($bounds, 'height');

            if ($x !== null && $y !== null && $width !== null && $height !== null) {
                return new DiagramBounds($x, $y, $width, $height);
            }
        }

        return null;
    }

    private function floatAttr(\DOMElement $element, string $name): ?float
    {
        $value = $this->attr($element, $name);

        return $value === null || !is_numeric($value) ? null : (float) $value;
    }

    /**
     * Child elements matched by local name regardless of namespace — for
     * the DMNDI/DC/DI companion namespaces, which vary per DMN version.
     *
     * @return \Generator<int, \DOMElement>
     */
    private function foreignChildren(\DOMElement $parent, ?string $localName = null): \Generator
    {
        foreach ($parent->childNodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            if ($localName === null || $node->localName === $localName) {
                yield $node;
            }
        }
    }

    /**
     * @return \Generator<int, \DOMElement>
     */
    private function childElements(\DOMElement $parent, ?string $localName = null): \Generator
    {
        foreach ($parent->childNodes as $node) {
            if (!$node instanceof \DOMElement || $node->namespaceURI !== $this->modelNamespace) {
                continue;
            }

            if ($localName === null || $node->localName === $localName) {
                yield $node;
            }
        }
    }

    private function textOf(\DOMElement $element): ?string
    {
        foreach ($this->childElements($element, 'text') as $textElement) {
            return trim($textElement->textContent);
        }

        return null;
    }

    private function attr(\DOMElement $element, string $name): ?string
    {
        return $element->hasAttribute($name) ? $element->getAttribute($name) : null;
    }

    private function attrOf(\DOMElement $parent, string $childLocalName, string $attribute): ?string
    {
        foreach ($this->childElements($parent, $childLocalName) as $child) {
            return $this->attr($child, $attribute);
        }

        return null;
    }

    private function elementId(\DOMElement $element): string
    {
        $id = $this->attr($element, 'id');

        if ($id !== null && $id !== '') {
            return $id;
        }

        return sprintf('%s-line-%d', (string) $element->localName, $element->getLineNo());
    }

    private function message(Severity $severity, string $message, \DOMElement $element): void
    {
        $this->messages[] = new ValidationMessage(
            $severity,
            $message,
            $this->attr($element, 'id'),
            $element->getLineNo(),
        );
    }

    private static function isFeelLanguage(string $language): bool
    {
        if (\in_array(strtolower($language), ['feel', 's-feel'], true)) {
            return true;
        }

        return preg_match('~^https?://.*(/|#)(S-)?FEEL/?$~i', $language) === 1
            || \in_array($language, DmnNamespaces::MODEL, true);
    }

    private static function isSFeelLanguage(string $language): bool
    {
        return strtolower($language) === 's-feel'
            || preg_match('~^https?://.*(/|#)S-FEEL/?$~i', $language) === 1;
    }

    /**
     * S-FEEL strict mode: when the expression's effective language (its own
     * attribute, falling back to the definitions default) claims S-FEEL,
     * full-FEEL constructs are rejected as validation errors.
     */
    private function checkSFeel(?Node $ast, ?string $language, \DOMElement $element): void
    {
        $language ??= $this->defaultExpressionLanguage;

        if ($ast === null || $language === null || !self::isSFeelLanguage($language)) {
            return;
        }

        foreach (SFeelChecker::violations($ast) as $construct) {
            $this->message(
                Severity::Error,
                sprintf('S-FEEL does not allow %s (the model declares an S-FEEL expression language)', $construct),
                $element,
            );
        }
    }
}
