<?php

namespace App\Domain\AiJobsPredictions\Actions;

final readonly class AiPredictionStatusSequenceRow
{
    private const INVALID_MESSAGE = 'Sequence detail has an invalid database representation.';

    public function __construct(
        public int $id,
        public string $sequenceUuid,
    ) {}

    public static function fromDatabaseRow(object $row): self
    {
        $values = get_object_vars($row);
        $value = $values['id'] ?? null;
        $sequenceUuid = $values['sequence_uuid'] ?? null;

        if (is_int($value) && $value > 0 && is_string($sequenceUuid) && trim($sequenceUuid) !== '') {
            return new self($value, $sequenceUuid);
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value)) {
            $normalized = filter_var($value, FILTER_VALIDATE_INT);

            if ($normalized !== false && $normalized > 0 && is_string($sequenceUuid) && trim($sequenceUuid) !== '') {
                return new self($normalized, $sequenceUuid);
            }
        }

        throw new PredictionStatusProjectionException(self::INVALID_MESSAGE);
    }
}
