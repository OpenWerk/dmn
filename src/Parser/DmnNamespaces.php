<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Parser;

/**
 * The DMN model namespace URIs accepted by the parser. The engine targets
 * DMN 1.5 execution semantics but ingests every model namespace from 1.1 on,
 * since all of them appear in the wild.
 */
final class DmnNamespaces
{
    public const array MODEL = [
        '1.1' => 'http://www.omg.org/spec/DMN/20151101/dmn.xsd',
        '1.2' => 'http://www.omg.org/spec/DMN/20180521/MODEL/',
        '1.3' => 'https://www.omg.org/spec/DMN/20191111/MODEL/',
        '1.4' => 'https://www.omg.org/spec/DMN/20211108/MODEL/',
        '1.5' => 'https://www.omg.org/spec/DMN/20230324/MODEL/',
    ];

    private function __construct()
    {
    }

    public static function versionOf(string $namespaceUri): ?string
    {
        $version = array_search($namespaceUri, self::MODEL, true);

        return $version === false ? null : $version;
    }
}
