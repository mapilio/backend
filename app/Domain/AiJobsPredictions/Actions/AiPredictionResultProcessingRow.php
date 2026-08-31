<?php

namespace App\Domain\AiJobsPredictions\Actions;

final readonly class AiPredictionResultProcessingRow
{
    private const INVALID_MESSAGE = 'AI processing request has an invalid database representation.';

    public function __construct(
        public string $responseId,
        public string $sequenceUuid,
        public ?int $createdById,
        public ?string $organizationKey,
        public ?string $projectKey,
    ) {}

    public static function fromDatabaseRow(object $row): self
    {
        $values = get_object_vars($row);

        return new self(
            self::requiredString(self::presentValue($values, 'response_id')),
            self::requiredString(self::presentValue($values, 'sequence_uuid')),
            self::nullableInteger(self::presentValue($values, 'created_by_id')),
            self::nullableString(self::presentValue($values, 'organization_key')),
            self::nullableString(self::presentValue($values, 'project_key')),
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

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        throw new PredictionResultPersistenceException(self::INVALID_MESSAGE);
    }

    private static function nullableInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) && $value > 0 && $value <= 2_147_483_647) {
            return $value;
        }

        if (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/D', $value)) {
            throw new PredictionResultPersistenceException(self::INVALID_MESSAGE);
        }

        $normalized = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 2_147_483_647,
            ],
        ]);

        if ($normalized === false) {
            throw new PredictionResultPersistenceException(self::INVALID_MESSAGE);
        }

        return $normalized;
    }
}
