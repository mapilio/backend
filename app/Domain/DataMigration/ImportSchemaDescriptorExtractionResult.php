<?php

namespace App\Domain\DataMigration;

final readonly class ImportSchemaDescriptorExtractionResult
{
    /** @param list<string> $checks */
    public function __construct(public array $checks) {}
}
