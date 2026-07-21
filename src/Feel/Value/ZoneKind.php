<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Value;

/**
 * How a `date and time` value is anchored: local (no zone information),
 * fixed UTC offset, or IANA zone id (`@Europe/Berlin`).
 */
enum ZoneKind: string
{
    case Local = 'local';
    case Offset = 'offset';
    case Zone = 'zone';
}
