<?php

namespace App\Domain\AiJobsPredictions\Actions;

use App\Support\Database\LegacyDatabase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MarkSequenceProcessingFailed
{
    private const PROCESS_STATUS_ERROR = 'ERROR';

    private const TERMINAL_MESSAGE = 'AI prediction processing failed.';

    public function markBySequenceUuid(string $sequenceUuid): bool
    {
        if (config('mapilio.ai_status_projection.enabled') !== true || trim($sequenceUuid) === '') {
            return false;
        }

        try {
            return $this->legacyConnection()->transaction(function () use ($sequenceUuid): bool {
                $connection = $this->legacyConnection();
                $sequence = $this->lockedUniqueSequence($connection, $sequenceUuid);

                if ($sequence === null) {
                    return false;
                }

                $processing = $connection->table('default_mapilio_processing')
                    ->where('sequence_uuid', $sequenceUuid)
                    ->whereNull('deleted_at')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first(['id', 'process_status']);

                if (! is_object($processing) || (string) (get_object_vars($processing)['process_status'] ?? '') !== self::PROCESS_STATUS_ERROR) {
                    return false;
                }

                return $this->markSequence($connection, $sequence);
            });
        } catch (Throwable) {
            return false;
        }
    }

    public function markByReceiptId(int $receiptId): bool
    {
        if (config('mapilio.ai_status_projection.enabled') !== true || $receiptId < 1) {
            return false;
        }

        try {
            $modernConnection = DB::connection();

            return $modernConnection->transaction(function () use ($modernConnection, $receiptId): bool {
                $receipt = $modernConnection->table('ai_prediction_callback_receipts')
                    ->where('id', $receiptId)
                    ->first(['id', 'response_id']);
                $receiptValues = is_object($receipt) ? get_object_vars($receipt) : [];
                $responseId = $receiptValues['response_id'] ?? null;

                if (! is_string($responseId) || trim($responseId) === '') {
                    return false;
                }

                $oldestReceipt = $modernConnection->table('ai_prediction_callback_receipts')
                    ->where('response_id', $responseId)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first(['id']);

                if (! is_object($oldestReceipt)) {
                    return false;
                }

                $lockedReceipt = $modernConnection->table('ai_prediction_callback_receipts')
                    ->where('id', $receiptId)
                    ->where('response_id', $responseId)
                    ->lockForUpdate()
                    ->first(['processing_status']);
                $lockedReceiptValues = is_object($lockedReceipt) ? get_object_vars($lockedReceipt) : [];
                $processingStatus = $lockedReceiptValues['processing_status'] ?? null;

                if (! is_string($processingStatus)) {
                    return false;
                }

                if ($modernConnection->table('ai_prediction_callback_receipts')
                    ->where('response_id', $responseId)
                    ->where('id', '>', $receiptId)
                    ->exists()) {
                    return false;
                }

                if (! $this->hasDurableReceiptFailure(
                    $modernConnection,
                    $receiptId,
                    $processingStatus,
                )) {
                    return false;
                }

                return $this->markReceiptOwnershipFailed($responseId);
            });
        } catch (Throwable) {
            return false;
        }
    }

    private function hasDurableReceiptFailure(
        Connection $connection,
        int $receiptId,
        string $processingStatus,
    ): bool {
        if ($processingStatus === 'error') {
            return true;
        }

        if ($processingStatus !== 'processed') {
            return false;
        }

        $projection = $connection->table('ai_prediction_status_projections')
            ->where('callback_receipt_id', $receiptId)
            ->lockForUpdate()
            ->first(['projection_status']);
        $projectionValues = is_object($projection) ? get_object_vars($projection) : [];

        return ($projectionValues['projection_status'] ?? null) === 'error';
    }

    private function markReceiptOwnershipFailed(string $responseId): bool
    {
        return $this->legacyConnection()->transaction(function () use ($responseId): bool {
            $connection = $this->legacyConnection();
            $processingRows = $connection->table('default_mapilio_processing')
                ->where('response_id', $responseId)
                ->whereNull('deleted_at')
                ->limit(2)
                ->get(['id', 'sequence_uuid']);

            if ($processingRows->count() !== 1) {
                return false;
            }

            $processing = $processingRows->first();

            $processingValues = is_object($processing) ? get_object_vars($processing) : [];
            $processingSequenceUuid = $processingValues['sequence_uuid'] ?? null;

            if (! is_string($processingSequenceUuid) || trim($processingSequenceUuid) === '') {
                return false;
            }

            $processingId = filter_var($processingValues['id'] ?? null, FILTER_VALIDATE_INT);

            if ($processingId === false || $processingId < 1) {
                return false;
            }

            $sequence = $this->lockedUniqueSequence($connection, $processingSequenceUuid);

            if ($sequence === null) {
                return false;
            }

            if ($connection->table('default_mapilio_processing')
                ->where('sequence_uuid', $sequence['sequence_uuid'])
                ->whereNull('deleted_at')
                ->where('id', '>', $processingId)
                ->exists()) {
                return false;
            }

            return $this->markSequence($connection, $sequence);
        });
    }

    /**
     * @return array{id: int, sequence_uuid: string, last_status: mixed}|null
     */
    private function lockedUniqueSequence(Connection $connection, string $sequenceUuid): ?array
    {
        $sequences = $connection->table('default_mapilio_sequence_detail')
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->limit(2)
            ->get(['id', 'sequence_uuid', 'last_status']);

        if ($sequences->count() !== 1) {
            return null;
        }

        $sequence = $sequences->first();

        if (! is_object($sequence)) {
            return null;
        }

        $sequenceValues = get_object_vars($sequence);
        $sequenceId = filter_var($sequenceValues['id'] ?? null, FILTER_VALIDATE_INT);
        $sequenceUuidValue = $sequenceValues['sequence_uuid'] ?? null;

        if ($sequenceId === false || $sequenceId < 1 || ! is_string($sequenceUuidValue)) {
            return null;
        }

        $locked = $connection->table('default_mapilio_sequence_detail')
            ->where('id', $sequenceId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first(['id', 'sequence_uuid', 'last_status']);

        $lockedValues = is_object($locked) ? get_object_vars($locked) : [];
        $lockedSequenceUuid = $lockedValues['sequence_uuid'] ?? null;

        if (! is_string($lockedSequenceUuid) || trim($lockedSequenceUuid) === '') {
            return null;
        }

        return [
            'id' => $sequenceId,
            'sequence_uuid' => $lockedSequenceUuid,
            'last_status' => $lockedValues['last_status'] ?? null,
        ];
    }

    /** @param array{id: int, sequence_uuid: string, last_status: mixed} $sequence */
    private function markSequence(Connection $connection, array $sequence): bool
    {
        if (in_array(trim((string) $sequence['last_status']), ['completed', 'fail'], true)) {
            return false;
        }

        $values = $this->onlyExistingColumns('default_mapilio_sequence_detail', [
            'last_status' => 'fail',
            'processing_status' => 1,
            'processing_status_message' => self::TERMINAL_MESSAGE,
            'updated_at' => now(),
        ]);

        foreach (['last_status', 'processing_status', 'processing_status_message'] as $column) {
            if (! array_key_exists($column, $values)) {
                return false;
            }
        }

        return $connection->table('default_mapilio_sequence_detail')
            ->where('id', $sequence['id'])
            ->update($values) > 0;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function onlyExistingColumns(string $table, array $values): array
    {
        $columns = Schema::connection(config('mapilio.legacy_database_connection'))
            ->getColumnListing($table);

        return array_intersect_key($values, array_flip($columns));
    }

    private function legacyConnection(): Connection
    {
        return LegacyDatabase::connection();
    }
}
