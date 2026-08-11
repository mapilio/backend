<?php

namespace App\Domain\GeoPublishing\Actions;

final readonly class AiPublicationFeatureSummaryRow
{
    private const INVALID_MESSAGE = 'Canonical detection features have an invalid database representation.';

    public function __construct(
        public string $sequenceUuid,
        public int $featureCount,
    ) {}

    public static function fromDatabaseRow(object $row): self
    {
        $values = get_object_vars($row);
        $sequenceUuid = $values['sequence_uuid'] ?? null;
        $featureCount = filter_var($values['feature_count'] ?? null, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 4_294_967_295,
            ],
        ]);

        if (! is_string($sequenceUuid) || trim($sequenceUuid) === '' || $featureCount === false) {
            throw new GeoPublicationException(self::INVALID_MESSAGE);
        }

        return new self($sequenceUuid, $featureCount);
    }
}
