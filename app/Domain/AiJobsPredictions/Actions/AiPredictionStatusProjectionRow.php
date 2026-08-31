<?php

namespace App\Domain\AiJobsPredictions\Actions;

final readonly class AiPredictionStatusProjectionRow
{
    private const INVALID_MESSAGE = 'Prediction projection has an invalid database representation.';

    public function __construct(
        public int $id,
        public string $projectionStatus,
    ) {}

    public static function fromDatabaseRow(object $row): self
    {
        $values = get_object_vars($row);

        return new self(
            self::positiveInt($values['id'] ?? null),
            self::requiredString($values['projection_status'] ?? null),
        );
    }

    private static function requiredString(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        throw new PredictionStatusProjectionException(self::INVALID_MESSAGE);
    }

    private static function positiveInt(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/', $value)) {
            throw new PredictionStatusProjectionException(self::INVALID_MESSAGE);
        }

        $normalized = filter_var($value, FILTER_VALIDATE_INT);

        if ($normalized === false || $normalized < 1) {
            throw new PredictionStatusProjectionException(self::INVALID_MESSAGE);
        }

        return $normalized;
    }
}
