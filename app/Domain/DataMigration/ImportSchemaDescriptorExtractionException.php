<?php

namespace App\Domain\DataMigration;

use RuntimeException;

final class ImportSchemaDescriptorExtractionException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
