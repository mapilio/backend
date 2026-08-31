<?php

namespace App\Domain\DataMigration;

final readonly class ImportSchemaFingerprintResult
{
    /** @param list<string> $checks */
    public function __construct(
        public string $fingerprint,
        public array $checks,
    ) {}
}
