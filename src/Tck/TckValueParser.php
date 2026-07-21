<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tck;

use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;
use OpenWerk\DecisionModelAndNotation\Feel\Value\TemporalParser;

/**
 * Maps DMN TCK test-case value XML (`<value>`, `<component>`, `<list>`) to
 * FEEL runtime values.
 *
 * @internal
 */
final class TckValueParser
{
    public const string TESTCASE_NS = 'http://www.omg.org/spec/DMN/20160719/testcase';

    private const string XSI_NS = 'http://www.w3.org/2001/XMLSchema-instance';

    private function __construct()
    {
    }

    /**
     * Parses the value structure inside an inputNode, expected, item or
     * component element.
     */
    public static function parseNode(\DOMElement $container): mixed
    {
        $components = [];
        $hasComponents = false;

        foreach ($container->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== self::TESTCASE_NS) {
                continue;
            }

            switch ($child->localName) {
                case 'value':
                    return self::scalar($child);

                case 'list':
                    return self::list($child);

                case 'component':
                    $name = $child->getAttribute('name');
                    $components[$name] = self::parseNode($child);
                    $hasComponents = true;
                    break;

                default:
                    break;
            }
        }

        if ($hasComponents) {
            return $components;
        }

        throw new TckFormatException(sprintf(
            'element <%s> at line %d carries no value, component or list',
            (string) $container->localName,
            $container->getLineNo(),
        ));
    }

    /**
     * @return list<mixed>
     */
    private static function list(\DOMElement $list): array
    {
        $items = [];

        foreach ($list->childNodes as $item) {
            if (
                $item instanceof \DOMElement
                && $item->namespaceURI === self::TESTCASE_NS
                && $item->localName === 'item'
            ) {
                $items[] = self::parseNode($item);
            }
        }

        return $items;
    }

    private static function scalar(\DOMElement $value): mixed
    {
        if ($value->getAttributeNS(self::XSI_NS, 'nil') === 'true') {
            return null;
        }

        $type = $value->getAttributeNS(self::XSI_NS, 'type');
        $localType = str_contains($type, ':') ? substr($type, (int) strrpos($type, ':') + 1) : $type;
        $text = $value->textContent;

        return match ($localType) {
            '', 'string' => $text,
            'decimal', 'integer', 'int', 'long', 'short', 'byte', 'double', 'float' => FeelNumber::of(trim($text)),
            'boolean' => trim($text) === 'true',
            'date' => TemporalParser::date(trim($text)),
            'time' => TemporalParser::time(trim($text)),
            'dateTime' => TemporalParser::dateAndTime(trim($text)),
            'duration' => TemporalParser::duration(trim($text)),
            default => throw new TckFormatException(sprintf(
                'unsupported value type %s at line %d',
                var_export($type, true),
                $value->getLineNo(),
            )),
        };
    }
}
