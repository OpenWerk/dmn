<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tck;

/**
 * A parsed TCK `*-test-*.xml` file: the model it exercises and its test
 * cases.
 *
 * @internal
 */
final class TckTestCasesFile
{
    /**
     * @param list<TckTestCase> $testCases
     */
    public function __construct(
        public readonly string $modelName,
        public readonly array $testCases,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $xml = @file_get_contents($path);

        if ($xml === false) {
            throw new TckFormatException(sprintf('cannot read TCK test file %s', var_export($path, true)));
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false) {
            throw new TckFormatException(sprintf('TCK test file %s is not well-formed XML', var_export($path, true)));
        }

        $root = $document->documentElement;

        if (
            $root === null
            || $root->localName !== 'testCases'
            || $root->namespaceURI !== TckValueParser::TESTCASE_NS
        ) {
            throw new TckFormatException(sprintf(
                'TCK test file %s has no <testCases> root in the TCK namespace',
                var_export($path, true),
            ));
        }

        $modelName = null;
        $testCases = [];

        foreach (self::children($root) as $child) {
            if ($child->localName === 'modelName') {
                $modelName = trim($child->textContent);
            }

            if ($child->localName === 'testCase') {
                $testCases[] = self::parseTestCase($child);
            }
        }

        if ($modelName === null || $modelName === '') {
            throw new TckFormatException(sprintf('TCK test file %s declares no modelName', var_export($path, true)));
        }

        return new self($modelName, $testCases);
    }

    private static function parseTestCase(\DOMElement $element): TckTestCase
    {
        $inputs = [];
        $namespacedInputs = [];
        $expectations = [];

        foreach (self::children($element) as $child) {
            if ($child->localName === 'inputNode') {
                $namespace = $child->getAttribute('namespace');

                if ($namespace !== '') {
                    $namespacedInputs[$namespace][$child->getAttribute('name')] = TckValueParser::parseNode($child);
                } else {
                    $inputs[$child->getAttribute('name')] = TckValueParser::parseNode($child);
                }
            }

            if ($child->localName === 'resultNode') {
                $expected = null;

                foreach (self::children($child, 'expected') as $expectedElement) {
                    $expected = TckValueParser::parseNode($expectedElement);
                }

                $expectations[] = new TckExpectation($child->getAttribute('name'), $expected);
            }
        }

        $id = $element->getAttribute('id');
        $invocableName = $element->getAttribute('invocableName');

        return new TckTestCase(
            $id === '' ? 'unnamed' : $id,
            $inputs,
            $expectations,
            $namespacedInputs,
            $invocableName === '' ? null : $invocableName,
        );
    }

    /**
     * @return \Generator<\DOMElement>
     */
    private static function children(\DOMElement $parent, ?string $localName = null): \Generator
    {
        foreach ($parent->childNodes as $node) {
            if (!$node instanceof \DOMElement || $node->namespaceURI !== TckValueParser::TESTCASE_NS) {
                continue;
            }

            if ($localName === null || $node->localName === $localName) {
                yield $node;
            }
        }
    }
}
