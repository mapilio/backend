<?php

namespace Tests\Unit;

use App\Domain\AiJobsPredictions\Actions\AiPredictionActiveProcessRow;
use App\Domain\AiJobsPredictions\Actions\AiPredictionSequenceDetailRow;
use App\Domain\AiJobsPredictions\Actions\PredictionDispatchException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AiPredictionDispatchRowsTest extends TestCase
{
    public function test_it_normalizes_active_process_rows(): void
    {
        $row = AiPredictionActiveProcessRow::fromDatabaseRow((object) [
            'process_status' => 'pending',
            'response_id' => 'dispatch:123',
        ]);

        $this->assertSame('pending', $row->processStatus);
        $this->assertSame('dispatch:123', $row->responseId);
    }

    public function test_it_normalizes_postgresql_and_sqlite_sequence_detail_rows(): void
    {
        $postgresql = AiPredictionSequenceDetailRow::fromDatabaseRow((object) [
            'created_by_id' => '9223372036854775807',
            'organization_key' => '',
            'project_key' => 'project-1',
        ]);
        $sqlite = AiPredictionSequenceDetailRow::fromDatabaseRow((object) [
            'created_by_id' => 7,
            'organization_key' => null,
            'project_key' => null,
        ]);

        $this->assertSame(PHP_INT_MAX, $postgresql->createdById);
        $this->assertSame('', $postgresql->organizationKey);
        $this->assertSame('project-1', $postgresql->projectKey);
        $this->assertSame(7, $sqlite->createdById);
        $this->assertNull($sqlite->organizationKey);
        $this->assertNull($sqlite->projectKey);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[DataProvider('invalidActiveRows')]
    public function test_it_rejects_invalid_active_process_rows(array $values): void
    {
        $this->expectException(PredictionDispatchException::class);
        $this->expectExceptionMessage('AI prediction processing row has an invalid database representation.');

        AiPredictionActiveProcessRow::fromDatabaseRow((object) $values);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidActiveRows(): array
    {
        return [
            'missing process status' => [['process_status' => null, 'response_id' => 'response-1']],
            'empty process status' => [['process_status' => '', 'response_id' => 'response-1']],
            'non-string process status' => [['process_status' => 1, 'response_id' => 'response-1']],
            'missing response id' => [['process_status' => 'pending', 'response_id' => null]],
            'empty response id' => [['process_status' => 'pending', 'response_id' => '']],
            'non-string response id' => [['process_status' => 'pending', 'response_id' => 1]],
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[DataProvider('invalidDetailRows')]
    public function test_it_rejects_invalid_sequence_detail_rows(array $values): void
    {
        $this->expectException(PredictionDispatchException::class);
        $this->expectExceptionMessage('AI prediction sequence detail has an invalid database representation.');

        AiPredictionSequenceDetailRow::fromDatabaseRow((object) $values);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidDetailRows(): array
    {
        $valid = [
            'created_by_id' => 7,
            'organization_key' => 'organization-1',
            'project_key' => 'project-1',
        ];

        return [
            'missing creator' => [['organization_key' => 'organization-1', 'project_key' => 'project-1']],
            'missing organization key' => [['created_by_id' => 7, 'project_key' => 'project-1']],
            'missing project key' => [['created_by_id' => 7, 'organization_key' => 'organization-1']],
            'non-integer creator' => [[...$valid, 'created_by_id' => 7.5]],
            'non-canonical creator' => [[...$valid, 'created_by_id' => '07']],
            'creator below integer range' => [[...$valid, 'created_by_id' => '-9223372036854775809']],
            'creator above integer range' => [[...$valid, 'created_by_id' => '9223372036854775808']],
            'invalid organization key' => [[...$valid, 'organization_key' => 1]],
            'invalid project key' => [[...$valid, 'project_key' => 1]],
        ];
    }
}
