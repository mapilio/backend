<?php

namespace App\Domain\OperationsDashboard\Actions;

use RuntimeException;

class BackupReadinessException extends RuntimeException
{
    /**
     * @param  list<string>  $failures
     */
    public function __construct(public readonly array $failures)
    {
        parent::__construct('Backup readiness evidence did not pass policy.');
    }
}
