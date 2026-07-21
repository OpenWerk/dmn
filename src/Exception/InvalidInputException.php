<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Exception;

/**
 * Thrown when caller-supplied input data cannot be converted into FEEL
 * values (for example resources, closures or non-finite floats).
 */
final class InvalidInputException extends \InvalidArgumentException implements DmnException
{
}
