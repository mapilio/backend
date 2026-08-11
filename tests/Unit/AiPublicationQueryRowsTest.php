<?php

namespace Tests\Unit;

use App\Domain\GeoPublishing\Actions\AiPublicationFeatureSummaryRow;
use App\Domain\GeoPublishing\Actions\AiPublicationReceiptRow;
use App\Domain\GeoPublishing\Actions\GeoPublicationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AiPublicationQueryRowsTest extends TestCase
{
    public function test_it_normalizes_a_postgresql_receipt_row(): void
    {
        $row = AiPublicationReceiptRow::fromDatabaseRow((object) [
            'processing_status' => 'processed',
            'response_status' => 'SUCCESS',
            'result_feature_count' => '2',
            'response_id' => 'prediction-result-1',
        ]);

        $this->assertSame('processed', $row->processingStatus);
        $this->assertSame('SUCCESS', $row->responseStatus);
        $this->assertSame(2, $row->resultFeatureCount);
        $this->assertSame('prediction-result-1', $row->responseId);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[DataProvider('invalidReceiptRows')]
    public function test_it_rejects_invalid_receipt_rows(array $values): void
    {
        $this->expectException(GeoPublicationException::class);
        $this->expectExceptionMessage('Callback receipt has an invalid database representation.');

        AiPublicationReceiptRow::fromDatabaseRow((object) $values);
    }

    public function test_it_normalizes_a_postgresql_feature_summary(): void
    {
        $row = AiPublicationFeatureSummaryRow::fromDatabaseRow((object) [
            'sequence_uuid' => 'sequence-ai-1',
            'feature_count' => '2',
        ]);

        $this->assertSame('sequence-ai-1', $row->sequenceUuid);
        $this->assertSame(2, $row->featureCount);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[DataProvider('invalidFeatureSummaryRows')]
    public function test_it_rejects_invalid_feature_summary_rows(array $values): void
    {
        $this->expectException(GeoPublicationException::class);
        $this->expectExceptionMessage('Canonical detection features have an invalid database representation.');

        AiPublicationFeatureSummaryRow::fromDatabaseRow((object) $values);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidReceiptRows(): array
    {
        $valid = [
            'processing_status' => 'processed',
            'response_status' => 'SUCCESS',
            'result_feature_count' => 2,
            'response_id' => 'prediction-result-1',
        ];

        return [
            'missing processing status' => [[...$valid, 'processing_status' => null]],
            'empty response status' => [[...$valid, 'response_status' => '']],
            'negative feature count' => [[...$valid, 'result_feature_count' => -1]],
            'overflowing feature count' => [[...$valid, 'result_feature_count' => '4294967296']],
            'missing response id' => [[...$valid, 'response_id' => null]],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidFeatureSummaryRows(): array
    {
        return [
            'missing sequence uuid' => [['feature_count' => 2]],
            'empty sequence uuid' => [['sequence_uuid' => '', 'feature_count' => 2]],
            'zero feature count' => [['sequence_uuid' => 'sequence-ai-1', 'feature_count' => 0]],
            'invalid feature count' => [['sequence_uuid' => 'sequence-ai-1', 'feature_count' => 'two']],
            'overflowing feature count' => [['sequence_uuid' => 'sequence-ai-1', 'feature_count' => '4294967296']],
        ];
    }
}
