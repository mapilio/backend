<?php

namespace App\Support\Http\BoundedRead;

use JsonException;

final class PublicReadBounds
{
    public const SEQUENCE = 'sequence';

    public const EMBED = 'embed';

    public const ROADS = 'roads';

    public const MAX_SEQUENCE_ROWS = 25_000;

    public const MAX_ROAD_ROWS = 10_000;

    public const MAX_ITEM_BYTES = 16 * 1024 * 1024;

    public static function enforced(): bool
    {
        return filter_var(
            config('mapilio.public_read_bounds.enabled', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) ?? true;
    }

    public static function maxRows(string $resource): int
    {
        $configured = match ($resource) {
            self::SEQUENCE, self::EMBED => config('mapilio.public_read_bounds.max_imagery_rows', self::MAX_SEQUENCE_ROWS),
            self::ROADS => config('mapilio.public_read_bounds.max_road_rows', self::MAX_ROAD_ROWS),
            default => self::MAX_SEQUENCE_ROWS,
        };

        $maximum = $resource === self::ROADS ? self::MAX_ROAD_ROWS : self::MAX_SEQUENCE_ROWS;

        return max(1, min($maximum, (int) $configured));
    }

    public static function maxItemBytes(): int
    {
        return max(
            1,
            min(self::MAX_ITEM_BYTES, (int) config('mapilio.public_read_bounds.max_item_bytes', self::MAX_ITEM_BYTES)),
        );
    }

    /** @param array<string, mixed> $item */
    public static function nextEncodedBytes(array $item, int $encodedBytes): int
    {
        try {
            $itemBytes = strlen(json_encode($item, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new PayloadTooLargeException('Public read item could not be encoded.', previous: $exception);
        }

        if ($itemBytes > self::maxItemBytes() - $encodedBytes) {
            throw new PayloadTooLargeException('Public read byte budget exceeded.');
        }

        return $encodedBytes + $itemBytes;
    }
}
