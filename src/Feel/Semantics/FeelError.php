<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Semantics;

/**
 * Internal signal for a FEEL evaluation error. Per the DMN specification an
 * erroneous (sub-)expression evaluates to null; the evaluator catches this
 * exception, records a diagnostic and substitutes null. It never crosses the
 * public API boundary.
 *
 * @internal
 */
final class FeelError extends \RuntimeException
{
}
