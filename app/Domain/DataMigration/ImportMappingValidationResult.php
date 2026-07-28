<?php

namespace App\Domain\DataMigration;

final class ImportMappingValidationResult
{
    /** @param list<string> $checks */
    public function __construct(
        public readonly bool $successful,
        public readonly array $checks,
        public readonly ?string $reasonCode = null,
    ) {}
}
