<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * Aggregation for the COLLECT hit policy.
 */
enum BuiltinAggregator: string
{
    case Sum = 'SUM';
    case Count = 'COUNT';
    case Min = 'MIN';
    case Max = 'MAX';
}
