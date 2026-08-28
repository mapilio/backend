<?php

namespace Tests\Unit;

use App\Domain\AiJobsPredictions\Actions\AiPredictionCallbackReceiptRow;
use App\Domain\AiJobsPredictions\Actions\PredictionCallbackException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AiPredictionCallbackReceiptRowTest extends TestCase
{
    public function test_it_normalizes_a_postgresql_receipt_row(): void
    {
        $row = AiPredictionCallbackReceiptRow::fromDatabaseRow((object) [
            'processing_status' => 'received',
            'encrypted_payload' => 'encrypted-callback-payload',
            'payload_hash' => str_repeat('a', 64),
            'response_id' => 'prediction-result-1',
            'response_status' => 'SUCCESS',
        ]);

        $this->assertSame('received', $row->processingStatus);
        $this->assertSame('encrypted-callback-payload', $row->encryptedPayload);
        $this->assertSame(str_repeat('a', 64), $row->payloadHash);
        $this->assertSame('prediction-result-1', $row->responseId);
        $this->assertSame('SUCCESS', $row->responseStatus);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[DataProvider('invalidRows')]
    public function test_it_rejects_invalid_database_rows(array $values): void
    {
        $this->expectException(PredictionCallbackException::class);
        $this->expectExceptionMessage('Callback receipt has an invalid database representation.');

        AiPredictionCallbackReceiptRow::fromDatabaseRow((object) $values);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidRows(): array
    {
        $valid = [
            'processing_status' => 'received',
            'encrypted_payload' => 'encrypted-callback-payload',
            'payload_hash' => str_repeat('a', 64),
            'response_id' => 'prediction-result-1',
            'response_status' => 'SUCCESS',
        ];

        return [
            'missing processing status' => [[...$valid, 'processing_status' => null]],
            'empty encrypted payload' => [[...$valid, 'encrypted_payload' => '']],
            'missing payload hash' => [[...$valid, 'payload_hash' => null]],
            'empty response id' => [[...$valid, 'response_id' => '']],
            'missing response status' => [[...$valid, 'response_status' => null]],
        ];
    }
}
