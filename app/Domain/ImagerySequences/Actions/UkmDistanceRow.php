<?php

namespace App\Domain\ImagerySequences\Actions;

use Illuminate\Support\Carbon;
use Throwable;

final readonly class UkmDistanceRow
{
    private const INVALID_MESSAGE = 'UKM scoring returned an invalid distance row.';

    public function __construct(
        public int $imageryId,
        public Carbon $captureTime,
        public ?float $distance,
    ) {}

    public static function fromDatabaseRow(object $row): self
    {
        $values = get_object_vars($row);
        $imageryId = self::imageryId($values['id'] ?? null);
        $captureTime = self::captureTime($values['capture_time'] ?? null);
        $distance = self::distance($values['distance'] ?? null);

        return new self($imageryId, $captureTime, $distance);
    }

    private static function imageryId(mixed $value): int
    {
        $imageryId = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($imageryId !== false) {
            return $imageryId;
        }

        throw new UkmScoringException(self::INVALID_MESSAGE);
    }

    private static function captureTime(mixed $value): Carbon
    {
        if (! is_string($value) || trim($value) === '' || strtotime($value) === false) {
            throw new UkmScoringException(self::INVALID_MESSAGE);
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            throw new UkmScoringException(self::INVALID_MESSAGE);
        }
    }

    private static function distance(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            throw new UkmScoringException(self::INVALID_MESSAGE);
        }

        $distance = (float) $value;

        if (! is_finite($distance)) {
            throw new UkmScoringException(self::INVALID_MESSAGE);
        }

        return $distance;
    }
}
