<?php

namespace App\Domain\AiJobsPredictions\Actions;

final readonly class AiPredictionResultLockedReceiptRow
{
    private const INVALID_MESSAGE = 'Callback receipt has an invalid database representation.';

    public function __construct(public string $processingStatus) {}

    public static function fromDatabaseRow(object $row): self
    {
        $values = get_object_vars($row);

        if (! array_key_exists('processing_status', $values)) {
            throw new PredictionResultPersistenceException(self::INVALID_MESSAGE);
        }

        $processingStatus = $values['processing_status'];

        if (! is_string($processingStatus) || trim($processingStatus) === '') {
            throw new PredictionResultPersistenceException(self::INVALID_MESSAGE);
        }

        return new self($processingStatus);
    }
}
