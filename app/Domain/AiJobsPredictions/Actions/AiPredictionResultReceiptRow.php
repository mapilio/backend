<?php

namespace App\Domain\AiJobsPredictions\Actions;

final readonly class AiPredictionResultReceiptRow
{
    private const INVALID_MESSAGE = 'Callback receipt has an invalid database representation.';

    public function __construct(
        public int $id,
        public string $processingStatus,
        public string $encryptedPayload,
        public string $responseId,
        public string $responseStatus,
        public int $resultFeatureCount,
    ) {}

    public static function fromDatabaseRow(object $row): self
    {
        $values = get_object_vars($row);

        return new self(
            self::positiveInteger(self::presentValue($values, 'id')),
            self::requiredString(self::presentValue($values, 'processing_status')),
            self::requiredString(self::presentValue($values, 'encrypted_payload')),
            self::requiredString(self::presentValue($values, 'response_id')),
            self::responseStatus(self::presentValue($values, 'response_status')),
            self::featureCount(self::presentValue($values, 'result_feature_count')),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function presentValue(array $values, string $key): mixed
    {
        if (array_key_exists($key, $values)) {
            return $values[$key];
        }

        throw new PredictionResultPersistenceException(self::INVALID_MESSAGE);
    }

    private static function requiredString(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        throw new PredictionResultPersistenceException(self::INVALID_MESSAGE);
    }

    private static function responseStatus(mixed $value): string
    {
        if ($value === 'SUCCESS' || $value === 'ERROR') {
            return $value;
        }

        throw new PredictionResultPersistenceException(self::INVALID_MESSAGE);
    }

    private static function featureCount(mixed $value): int
    {
        if (is_int($value) && $value >= 0 && $value <= 4_294_967_295) {
            return $value;
        }

        if (! is_string($value) || ! preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
            throw new PredictionResultPersistenceException(self::INVALID_MESSAGE);
        }

        $normalized = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 0,
                'max_range' => 4_294_967_295,
            ],
        ]);

        if ($normalized === false) {
            throw new PredictionResultPersistenceException(self::INVALID_MESSAGE);
        }

        return $normalized;
    }

    private static function positiveInteger(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/D', $value)) {
            throw new PredictionResultPersistenceException(self::INVALID_MESSAGE);
        }

        $normalized = filter_var($value, FILTER_VALIDATE_INT);

        if ($normalized === false || $normalized < 1) {
            throw new PredictionResultPersistenceException(self::INVALID_MESSAGE);
        }

        return $normalized;
    }
}
