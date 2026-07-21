<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Exception;

/**
 * Thrown when a caller asks a model to evaluate a decision (or decision
 * service) that does not exist in the model. This is an API misuse, not an
 * evaluation error, which is why it throws instead of yielding a diagnostic.
 */
final class UnknownDecisionException extends \InvalidArgumentException implements DmnException
{
}
