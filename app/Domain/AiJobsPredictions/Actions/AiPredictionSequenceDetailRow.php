<?php

namespace App\Domain\AiJobsPredictions\Actions;

final readonly class AiPredictionSequenceDetailRow
{
    private const INVALID_MESSAGE = 'AI prediction sequence detail has an invalid database representation.';

    public function __construct(
        public ?int $createdById,
        public ?string $organizationKey,
        public ?string $projectKey,
    ) {}

    public static function fromDatabaseRow(object $row): self
    {
        $values = get_object_vars($row);

        return new self(
            self::nullableInt(self::presentValue($values, 'created_by_id')),
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

        throw new PredictionDispatchException(self::INVALID_MESSAGE);
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new PredictionDispatchException(self::INVALID_MESSAGE);
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || ! preg_match('/^(?:0|-?[1-9][0-9]*)$/', $value)) {
            throw new PredictionDispatchException(self::INVALID_MESSAGE);
        }

        $normalized = filter_var($value, FILTER_VALIDATE_INT);

        if ($normalized === false) {
            throw new PredictionDispatchException(self::INVALID_MESSAGE);
        }

        return $normalized;
    }
}
