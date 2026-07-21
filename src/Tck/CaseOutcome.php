<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tck;

/**
 * @internal
 */
enum CaseOutcome: string
{
    case Success = 'SUCCESS';
    case Failure = 'FAILURE';
    case Error = 'ERROR';
}
