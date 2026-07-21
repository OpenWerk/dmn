<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Model;

/**
 * A model-to-model import. Captured as metadata; namespace-qualified
 * resolution of imported elements arrives in M2.
 */
final class Import
{
    public function __construct(
        public readonly string $namespace,
        public readonly ?string $name = null,
        public readonly ?string $importType = null,
        public readonly ?string $locationUri = null,
    ) {
    }
}
