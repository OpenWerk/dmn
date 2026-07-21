<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation;

use OpenWerk\DecisionModelAndNotation\Exception\ParseException;
use OpenWerk\DecisionModelAndNotation\Parser\DirectoryImportResolver;
use OpenWerk\DecisionModelAndNotation\Parser\DmnParser;
use OpenWerk\DecisionModelAndNotation\Parser\ImportResolver;

/**
 * Entry point: parse DMN XML into an evaluatable, cacheable model.
 *
 * ```php
 * $model  = Dmn::fromFile('fees.dmn');
 * $result = $model->evaluateDecision('Fee', ['applicant' => ['age' => 34]]);
 * $result->value();
 * $result->diagnostics();
 * ```
 */
final class Dmn
{
    private function __construct()
    {
    }

    /**
     * Parses a DMN file. Model imports resolve against the sibling `.dmn`
     * files of the same directory (match by model namespace) unless a
     * custom {@see ImportResolver} is given.
     */
    public static function fromFile(string $path, ?ImportResolver $imports = null): DmnModel
    {
        $xml = @file_get_contents($path);

        if ($xml === false) {
            throw new ParseException(sprintf('cannot read DMN file %s', var_export($path, true)));
        }

        return self::fromString($xml, $imports ?? new DirectoryImportResolver(\dirname($path)));
    }

    /**
     * Parses DMN XML. Model imports only resolve when an
     * {@see ImportResolver} is supplied.
     */
    public static function fromString(string $xml, ?ImportResolver $imports = null): DmnModel
    {
        $result = (new DmnParser($imports))->parse($xml);

        return new DmnModel($result->definitions, $result->messages);
    }
}
