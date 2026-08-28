<?php

namespace App\Domain\AiJobsPredictions\Actions;

final readonly class AiPredictionActiveProcessRow
{
    private const INVALID_MESSAGE = 'AI prediction processing row has an invalid database representation.';

    public function __construct(
        public string $processStatus,
        public string $responseId,
    ) {}

    public static function fromDatabaseRow(object $row): self
    {
        $values = get_object_vars($row);

        return new self(
            self::requiredString($values['process_status'] ?? null),
            self::requiredString($values['response_id'] ?? null),
        );
    }

    private static function requiredString(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        throw new PredictionDispatchException(self::INVALID_MESSAGE);
    }
}
