<?php

namespace Tests\Unit;

use App\Domain\AiJobsPredictions\Actions\AiPredictionResultLockedReceiptRow;
use App\Domain\AiJobsPredictions\Actions\AiPredictionResultProcessingRow;
use App\Domain\AiJobsPredictions\Actions\AiPredictionResultReceiptRow;
use App\Domain\AiJobsPredictions\Actions\PredictionResultPersistenceException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AiPredictionResultPersistenceRowContractsTest extends TestCase
{
    public function test_it_normalizes_sqlite_and_postgresql_like_rows(): void
    {
        $sqliteReceipt = AiPredictionResultReceiptRow::fromDatabaseRow((object) [
            'id' => 7,
            'processing_status' => 'validated',
            'encrypted_payload' => 'encrypted-payload',
            'response_id' => 'prediction-1',
            'response_status' => 'SUCCESS',
            'result_feature_count' => 3,
        ]);
        $postgresqlReceipt = AiPredictionResultReceiptRow::fromDatabaseRow((object) [
            'id' => '9223372036854775807',
            'processing_status' => 'validated',
            'encrypted_payload' => 'encrypted-payload',
            'response_id' => 'prediction-1',
            'response_status' => 'SUCCESS',
            'result_feature_count' => '4294967295',
        ]);
        $locked = AiPredictionResultLockedReceiptRow::fromDatabaseRow((object) [
            'processing_status' => 'validated',
        ]);
        $errorReceipt = AiPredictionResultReceiptRow::fromDatabaseRow((object) [
            'id' => 8,
            'processing_status' => 'validated',
            'encrypted_payload' => 'encrypted-payload',
            'response_id' => 'prediction-2',
            'response_status' => 'ERROR',
            'result_feature_count' => 0,
        ]);
        $sqliteProcessing = AiPredictionResultProcessingRow::fromDatabaseRow((object) [
            'response_id' => 'prediction-1',
            'sequence_uuid' => 'sequence-1',
            'created_by_id' => null,
            'organization_key' => null,
            'project_key' => null,
        ]);
        $postgresqlProcessing = AiPredictionResultProcessingRow::fromDatabaseRow((object) [
            'response_id' => 'prediction-1',
            'sequence_uuid' => 'sequence-1',
            'created_by_id' => '2147483647',
            'organization_key' => 'org-1',
            'project_key' => 'project-1',
        ]);

        $this->assertSame(7, $sqliteReceipt->id);
        $this->assertSame(PHP_INT_MAX, $postgresqlReceipt->id);
        $this->assertSame(3, $sqliteReceipt->resultFeatureCount);
        $this->assertSame(4_294_967_295, $postgresqlReceipt->resultFeatureCount);
        $this->assertSame('ERROR', $errorReceipt->responseStatus);
        $this->assertSame('validated', $locked->processingStatus);
        $this->assertNull($sqliteProcessing->createdById);
        $this->assertNull($sqliteProcessing->organizationKey);
        $this->assertSame(2_147_483_647, $postgresqlProcessing->createdById);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[DataProvider('invalidReceiptRows')]
    public function test_it_rejects_invalid_initial_receipt_rows(array $values): void
    {
        $this->expectException(PredictionResultPersistenceException::class);
        $this->expectExceptionMessage('Callback receipt has an invalid database representation.');

        AiPredictionResultReceiptRow::fromDatabaseRow((object) $values);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[DataProvider('invalidLockedRows')]
    public function test_it_rejects_invalid_locked_receipt_rows(array $values): void
    {
        $this->expectException(PredictionResultPersistenceException::class);
        $this->expectExceptionMessage('Callback receipt has an invalid database representation.');

        AiPredictionResultLockedReceiptRow::fromDatabaseRow((object) $values);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[DataProvider('invalidProcessingRows')]
    public function test_it_rejects_invalid_processing_rows(array $values): void
    {
        $this->expectException(PredictionResultPersistenceException::class);
        $this->expectExceptionMessage('AI processing request has an invalid database representation.');

        AiPredictionResultProcessingRow::fromDatabaseRow((object) $values);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidReceiptRows(): array
    {
        $valid = [
            'id' => 1,
            'processing_status' => 'validated',
            'encrypted_payload' => 'encrypted-payload',
            'response_id' => 'prediction-1',
            'response_status' => 'SUCCESS',
            'result_feature_count' => 1,
        ];

        return [
            'missing id' => [array_diff_key($valid, ['id' => true])],
            'blank processing status' => [[...$valid, 'processing_status' => '  ']],
            'unknown response status' => [[...$valid, 'response_status' => 'PARTIAL']],
            'lowercase response status' => [[...$valid, 'response_status' => 'success']],
            'blank response status' => [[...$valid, 'response_status' => '']],
            'non-string response status' => [[...$valid, 'response_status' => 1]],
            'boolean id' => [[...$valid, 'id' => true]],
            'float feature count' => [[...$valid, 'result_feature_count' => 1.0]],
            'noncanonical feature count' => [[...$valid, 'result_feature_count' => '01']],
            'feature count overflow' => [[...$valid, 'result_feature_count' => '4294967296']],
            'id overflow' => [[...$valid, 'id' => '9223372036854775808']],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidLockedRows(): array
    {
        return [
            'missing status' => [[]],
            'blank status' => [['processing_status' => '']],
            'boolean status' => [['processing_status' => false]],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidProcessingRows(): array
    {
        $valid = [
            'response_id' => 'prediction-1',
            'sequence_uuid' => 'sequence-1',
            'created_by_id' => 7,
            'organization_key' => 'org-1',
            'project_key' => 'project-1',
        ];

        return [
            'missing response id' => [array_diff_key($valid, ['response_id' => true])],
            'missing sequence uuid' => [array_diff_key($valid, ['sequence_uuid' => true])],
            'missing nullable creator' => [array_diff_key($valid, ['created_by_id' => true])],
            'missing nullable organization' => [array_diff_key($valid, ['organization_key' => true])],
            'missing nullable project' => [array_diff_key($valid, ['project_key' => true])],
            'blank sequence uuid' => [[...$valid, 'sequence_uuid' => '  ']],
            'blank organization' => [[...$valid, 'organization_key' => '  ']],
            'blank project' => [[...$valid, 'project_key' => '  ']],
            'boolean creator' => [[...$valid, 'created_by_id' => true]],
            'float creator' => [[...$valid, 'created_by_id' => 7.5]],
            'zero creator' => [[...$valid, 'created_by_id' => 0]],
            'negative creator' => [[...$valid, 'created_by_id' => -1]],
            'negative string creator' => [[...$valid, 'created_by_id' => '-1']],
            'noncanonical creator' => [[...$valid, 'created_by_id' => '07']],
            'creator overflow' => [[...$valid, 'created_by_id' => '2147483648']],
            'creator underflow' => [[...$valid, 'created_by_id' => '-2147483649']],
        ];
    }
}
