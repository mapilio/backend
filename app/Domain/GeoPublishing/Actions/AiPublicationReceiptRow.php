<?php

namespace App\Domain\GeoPublishing\Actions;

final readonly class AiPublicationReceiptRow
{
    private const INVALID_MESSAGE = 'Callback receipt has an invalid database representation.';

    public function __construct(
        public string $processingStatus,
        public string $responseStatus,
        public int $resultFeatureCount,
        public string $responseId,
    ) {}

    public static function fromDatabaseRow(object $row): self
    {
        $values = get_object_vars($row);

        return new self(
            self::requiredString($values['processing_status'] ?? null),
            self::requiredString($values['response_status'] ?? null),
            self::featureCount($values['result_feature_count'] ?? null),
            self::requiredString($values['response_id'] ?? null),
        );
    }

    private static function requiredString(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        throw new GeoPublicationException(self::INVALID_MESSAGE);
    }

    private static function featureCount(mixed $value): int
    {
        $featureCount = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 0,
                'max_range' => 4_294_967_295,
            ],
        ]);

        if ($featureCount !== false) {
            return $featureCount;
        }

        throw new GeoPublicationException(self::INVALID_MESSAGE);
    }
}
