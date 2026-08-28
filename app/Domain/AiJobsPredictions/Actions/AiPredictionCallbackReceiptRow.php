<?php

namespace App\Domain\AiJobsPredictions\Actions;

final readonly class AiPredictionCallbackReceiptRow
{
    private const INVALID_MESSAGE = 'Callback receipt has an invalid database representation.';

    public function __construct(
        public string $processingStatus,
        public string $encryptedPayload,
        public string $payloadHash,
        public string $responseId,
        public string $responseStatus,
    ) {}

    public static function fromDatabaseRow(object $row): self
    {
        $values = get_object_vars($row);

        return new self(
            self::processingStatusFromDatabaseRow($row),
            self::requiredString($values['encrypted_payload'] ?? null),
            self::requiredString($values['payload_hash'] ?? null),
            self::requiredString($values['response_id'] ?? null),
            self::requiredString($values['response_status'] ?? null),
        );
    }

    public static function processingStatusFromDatabaseRow(object $row): string
    {
        $values = get_object_vars($row);

        return self::requiredString($values['processing_status'] ?? null);
    }

    private static function requiredString(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        throw new PredictionCallbackException(self::INVALID_MESSAGE);
    }
}
