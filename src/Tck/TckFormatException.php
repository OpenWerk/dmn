<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tck;

use OpenWerk\DecisionModelAndNotation\Exception\DmnException;

/**
 * Thrown when a TCK test-case file cannot be read or does not follow the
 * TCK test-case schema.
 *
 * @internal
 */
final class TckFormatException extends \RuntimeException implements DmnException
{
}
