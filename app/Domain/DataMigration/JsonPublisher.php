<?php

namespace App\Domain\DataMigration;

interface JsonPublisher
{
    public function publish(string $directory, string $filename, string $json): void;
}
