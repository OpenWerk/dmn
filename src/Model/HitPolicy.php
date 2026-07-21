<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

enum HitPolicy: string
{
    case Unique = 'UNIQUE';
    case First = 'FIRST';
    case Priority = 'PRIORITY';
    case Any = 'ANY';
    case Collect = 'COLLECT';
    case RuleOrder = 'RULE ORDER';
    case OutputOrder = 'OUTPUT ORDER';

    public function isMultiHit(): bool
    {
        return match ($this) {
            self::Collect, self::RuleOrder, self::OutputOrder => true,
            default => false,
        };
    }
}
