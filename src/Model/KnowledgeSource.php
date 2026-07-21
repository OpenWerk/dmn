<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * Knowledge sources are authority metadata only — they never influence
 * evaluation.
 */
final class KnowledgeSource
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {
    }
}
