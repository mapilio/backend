<?php

namespace Tests\Unit;

use App\Domain\AiJobsPredictions\Actions\AiPredictionStatusProcessingRow;
use App\Domain\AiJobsPredictions\Actions\AiPredictionStatusProjectionRow;
use App\Domain\AiJobsPredictions\Actions\AiPredictionStatusReceiptRow;
use App\Domain\AiJobsPredictions\Actions\AiPredictionStatusSequenceRow;
use App\Domain\AiJobsPredictions\Actions\PredictionStatusProjectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AiPredictionStatusRowsTest extends TestCase
{
    public function test_it_normalizes_sqlite_and_postgresql_like_rows(): void
    {
        $receipt = AiPredictionStatusReceiptRow::fromDatabaseRow((object) [
            'id' => '9223372036854775807',
            'processing_status' => 'processed',
            'response_id' => 'prediction-1',
            'response_status' => 'SUCCESS',
        ]);
        $processing = AiPredictionStatusProcessingRow::fromDatabaseRow((object) [
            'id' => 7,
            'sequence_uuid' => 'sequence-1',
        ]);
        $sequence = AiPredictionStatusSequenceRow::fromDatabaseRow((object) ['id' => '8', 'sequence_uuid' => 'sequence-1']);
        $projection = AiPredictionStatusProjectionRow::fromDatabaseRow((object) [
            'id' => 9,
            'projection_status' => 'pending',
        ]);

        $this->assertSame(PHP_INT_MAX, $receipt->id);
        $this->assertSame(7, $processing->id);
        $this->assertSame(8, $sequence->id);
        $this->assertSame('sequence-1', $sequence->sequenceUuid);
        $this->assertSame('pending', $projection->projectionStatus);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  class-string  $contract
     */
    #[DataProvider('invalidRows')]
    public function test_it_rejects_missing_and_malformed_fields(array $row, string $contract): void
    {
        $this->expectException(PredictionStatusProjectionException::class);

        $contract::fromDatabaseRow((object) $row);
    }

    /**
     * @return array<string, array{array<string, mixed>, class-string}>
     */
    public static function invalidRows(): array
    {
        $receipt = [
            'id' => 1,
            'processing_status' => 'processed',
            'response_id' => 'prediction-1',
            'response_status' => 'SUCCESS',
        ];

        return [
            'missing receipt id' => [[...$receipt, 'id' => null], AiPredictionStatusReceiptRow::class],
            'boolean receipt id' => [[...$receipt, 'id' => true], AiPredictionStatusReceiptRow::class],
            'float receipt id' => [[...$receipt, 'id' => 1.5], AiPredictionStatusReceiptRow::class],
            'non-canonical receipt id' => [[...$receipt, 'id' => '01'], AiPredictionStatusReceiptRow::class],
            'out of range receipt id' => [[...$receipt, 'id' => '9223372036854775808'], AiPredictionStatusReceiptRow::class],
            'blank response id' => [[...$receipt, 'response_id' => '  '], AiPredictionStatusReceiptRow::class],
            'missing processing sequence uuid' => [['id' => 1], AiPredictionStatusProcessingRow::class],
            'blank sequence uuid' => [['id' => 1, 'sequence_uuid' => ''], AiPredictionStatusProcessingRow::class],
            'missing sequence id' => [['sequence_uuid' => 'sequence-1'], AiPredictionStatusSequenceRow::class],
            'missing sequence uuid' => [['id' => 1], AiPredictionStatusSequenceRow::class],
            'float sequence id' => [['id' => 1.5, 'sequence_uuid' => 'sequence-1'], AiPredictionStatusSequenceRow::class],
            'missing projection status' => [['id' => 1], AiPredictionStatusProjectionRow::class],
            'boolean projection id' => [['id' => false, 'projection_status' => 'pending'], AiPredictionStatusProjectionRow::class],
        ];
    }
}
