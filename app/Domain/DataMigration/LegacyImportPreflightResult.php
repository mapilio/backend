<?php

namespace App\Domain\DataMigration;

final class LegacyImportPreflightResult
{
    /** @param list<string> $checks */
    public function __construct(
        public readonly bool $successful,
        public readonly array $checks,
        public readonly ?string $reasonCode = null,
    ) {}
}
