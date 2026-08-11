<?php

namespace Tests\Unit;

use App\Domain\ImagerySequences\Actions\UkmDistanceRow;
use App\Domain\ImagerySequences\Actions\UkmScoringException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UkmDistanceRowTest extends TestCase
{
    public function test_it_normalizes_postgresql_scalar_values(): void
    {
        $row = UkmDistanceRow::fromDatabaseRow((object) [
            'id' => '41',
            'capture_time' => '2026-07-01 10:00:00',
            'distance' => '20.25',
        ]);

        $this->assertSame(41, $row->imageryId);
        $this->assertSame('2026-07-01 10:00:00', $row->captureTime->format('Y-m-d H:i:s'));
        $this->assertSame(20.25, $row->distance);
    }

    public function test_it_preserves_a_missing_neighbor(): void
    {
        $row = UkmDistanceRow::fromDatabaseRow((object) [
            'id' => 42,
            'capture_time' => '2026-07-01 10:00:00',
            'distance' => null,
        ]);

        $this->assertNull($row->distance);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[DataProvider('invalidRows')]
    public function test_it_rejects_invalid_database_rows(array $values): void
    {
        $this->expectException(UkmScoringException::class);
        $this->expectExceptionMessage('UKM scoring returned an invalid distance row.');

        UkmDistanceRow::fromDatabaseRow((object) $values);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidRows(): array
    {
        return [
            'missing id' => [['capture_time' => '2026-07-01 10:00:00', 'distance' => 20]],
            'zero id' => [['id' => 0, 'capture_time' => '2026-07-01 10:00:00', 'distance' => 20]],
            'overflowing id' => [['id' => '999999999999999999999999', 'capture_time' => '2026-07-01 10:00:00', 'distance' => 20]],
            'missing capture time' => [['id' => 1, 'distance' => 20]],
            'invalid capture time' => [['id' => 1, 'capture_time' => 'not-a-date', 'distance' => 20]],
            'invalid distance' => [['id' => 1, 'capture_time' => '2026-07-01 10:00:00', 'distance' => 'far']],
            'non finite distance' => [['id' => 1, 'capture_time' => '2026-07-01 10:00:00', 'distance' => INF]],
        ];
    }
}
