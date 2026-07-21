<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Engine;

/**
 * The instant now() and today() report, shared across an evaluation run
 * and its service and import child runs. Resolution is lazy: when no
 * instant was injected, the current UTC instant pins on first use, so
 * evaluations that never ask for the clock never pay for it, and still
 * see one single instant per run when they do.
 *
 * @internal
 */
final class EvaluationClock
{
    private ?\DateTimeImmutable $instant;

    public function __construct(?\DateTimeImmutable $instant = null)
    {
        $this->instant = $instant;
    }

    public function instant(): \DateTimeImmutable
    {
        return $this->instant ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
