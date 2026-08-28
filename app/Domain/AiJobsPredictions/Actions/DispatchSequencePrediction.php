<?php

namespace App\Domain\AiJobsPredictions\Actions;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class DispatchSequencePrediction
{
    private const STATUS_ERROR = 'ERROR';

    private const STATUS_PENDING = 'pending';

    private const STATUS_RESERVING = 'dispatching';

    private const STATUS_SUCCESS = 'SUCCESS';

    /**
     * @return array{dispatched: bool, status: string, response_id: string|null}
     */
    public function dispatch(string $sequenceUuid): array
    {
        if (! config('mapilio.ai_prediction.enabled')) {
            return [
                'dispatched' => false,
                'status' => 'disabled',
                'response_id' => null,
            ];
        }

        $endpoint = trim((string) config('mapilio.ai_prediction.endpoint'));
        $configUrl = $this->configUrl($sequenceUuid);

        if ($endpoint === '' || $configUrl === '') {
            throw new PredictionDispatchException('AI prediction endpoint and config URL must be configured.');
        }

        if (app()->environment('production') && parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
            throw new PredictionDispatchException('AI prediction endpoint must use HTTPS in production.');
        }

        $connection = DB::connection(config('mapilio.legacy_database_connection'));
        $reservation = $this->reserveOrReuse($connection, $sequenceUuid);
        $existing = $reservation['existing'];

        if ($existing !== null) {
            return [
                'dispatched' => false,
                'status' => $existing->processStatus,
                'response_id' => $existing->responseId,
            ];
        }

        $reservationId = $reservation['id'];

        try {
            $payload = $this->payload($connection, $sequenceUuid, $configUrl);
            $response = $this->request($sequenceUuid)->post($endpoint, $payload);

            if (! $response->successful()) {
                throw new PredictionDispatchException("AI prediction request failed with HTTP {$response->status()}.");
            }

            $responseId = trim((string) $response->json('id'));

            if ($responseId === '') {
                throw new PredictionDispatchException('AI prediction response did not contain an id.');
            }

            $connection->transaction(function () use ($connection, $reservationId, $sequenceUuid, $responseId): void {
                $connection->table('default_mapilio_processing')
                    ->where('id', $reservationId)
                    ->update($this->onlyExistingColumns('default_mapilio_processing', [
                        'response_id' => $responseId,
                        'process_status' => self::STATUS_PENDING,
                        'updated_at' => Carbon::now(),
                    ]));

                $this->updateSequenceStatus($connection, $sequenceUuid, [
                    'last_status' => 'processing',
                    'processing_status' => 2,
                    'processing_status_message' => null,
                    'updated_at' => Carbon::now(),
                ]);
            });

            return [
                'dispatched' => true,
                'status' => self::STATUS_PENDING,
                'response_id' => $responseId,
            ];
        } catch (Throwable $exception) {
            $message = $exception instanceof PredictionDispatchException
                ? Str::limit($exception->getMessage(), 1000, '')
                : 'AI prediction request could not be completed.';

            $connection->transaction(function () use ($connection, $reservationId, $sequenceUuid, $message): void {
                $connection->table('default_mapilio_processing')
                    ->where('id', $reservationId)
                    ->update($this->onlyExistingColumns('default_mapilio_processing', [
                        'process_status' => self::STATUS_ERROR,
                        'updated_at' => Carbon::now(),
                    ]));

                $this->updateSequenceStatus($connection, $sequenceUuid, [
                    'last_status' => 'uploaded',
                    'processing_status' => 1,
                    'processing_status_message' => $message,
                    'updated_at' => Carbon::now(),
                ]);
            });

            if ($exception instanceof PredictionDispatchException) {
                throw $exception;
            }

            throw new PredictionDispatchException($message, previous: $exception);
        }
    }

    private function activeProcessing(ConnectionInterface $connection, string $sequenceUuid): ?AiPredictionActiveProcessRow
    {
        $row = $connection->table('default_mapilio_processing')
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->whereIn('process_status', [
                self::STATUS_RESERVING,
                self::STATUS_PENDING,
                self::STATUS_SUCCESS,
            ])
            ->orderByDesc('id')
            ->first();

        return $row === null ? null : AiPredictionActiveProcessRow::fromDatabaseRow($row);
    }

    /**
     * @return array{existing: AiPredictionActiveProcessRow|null, id: int|null}
     */
    private function reserveOrReuse(ConnectionInterface $connection, string $sequenceUuid): array
    {
        return $connection->transaction(function () use ($connection, $sequenceUuid): array {
            $detail = $connection->table('default_mapilio_sequence_detail')
                ->where('sequence_uuid', $sequenceUuid)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if ($detail === null) {
                throw new PredictionDispatchException("Sequence {$sequenceUuid} was not found.");
            }

            $this->expireStaleReservations($connection, $sequenceUuid);
            $existing = $this->activeProcessing($connection, $sequenceUuid);

            if ($existing !== null) {
                return ['existing' => $existing, 'id' => null];
            }

            return [
                'existing' => null,
                'id' => $this->reserve(
                    $connection,
                    $sequenceUuid,
                    AiPredictionSequenceDetailRow::fromDatabaseRow($detail),
                ),
            ];
        });
    }

    private function expireStaleReservations(ConnectionInterface $connection, string $sequenceUuid): void
    {
        $connectionName = config('mapilio.legacy_database_connection');

        if (! Schema::connection($connectionName)->hasColumn('default_mapilio_processing', 'updated_at')) {
            return;
        }

        $connection->table('default_mapilio_processing')
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->where('process_status', self::STATUS_RESERVING)
            ->where('updated_at', '<', Carbon::now()->subSeconds(
                (int) config('mapilio.ai_prediction.reservation_ttl', 900),
            ))
            ->update([
                'process_status' => self::STATUS_ERROR,
                'updated_at' => Carbon::now(),
            ]);
    }

    private function reserve(
        ConnectionInterface $connection,
        string $sequenceUuid,
        AiPredictionSequenceDetailRow $detail,
    ): int {
        return $connection->table('default_mapilio_processing')->insertGetId(
            $this->onlyExistingColumns('default_mapilio_processing', [
                'created_at' => Carbon::now(),
                'created_by_id' => $detail->createdById,
                'updated_at' => Carbon::now(),
                'updated_by_id' => $detail->createdById,
                'deleted_at' => null,
                'organization_key' => $detail->organizationKey,
                'project_key' => $detail->projectKey,
                'sequence_uuid' => $sequenceUuid,
                'process_status' => self::STATUS_RESERVING,
                'response_id' => 'dispatch:'.Str::uuid(),
            ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ConnectionInterface $connection, string $sequenceUuid, string $configUrl): array
    {
        $entries = $connection->table('default_mapilio_imagery')
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->where('anomaly', false)
            ->orderBy('id')
            ->get([
                'id',
                'roll',
                'yaw',
                'pitch',
                'filename',
                'uploaded_hash',
                'fov',
                'vfov',
                'heading',
                'longitude',
                'latitude',
                'altitude',
            ])
            ->map(static fn (object $entry): array => [
                'key' => (int) $entry->id,
                'roll' => (float) $entry->roll,
                'yaw' => (float) $entry->yaw,
                'pitch' => (float) $entry->pitch,
                'filename' => (string) $entry->filename,
                'hash' => (string) $entry->uploaded_hash,
                'fov' => (float) $entry->fov,
                'vfov' => $entry->vfov === null ? null : (float) $entry->vfov,
                'heading' => (float) $entry->heading,
                'coordx' => (float) $entry->longitude,
                'coordy' => (float) $entry->latitude,
                'altitude' => max(0.0, (float) $entry->altitude),
            ])
            ->all();

        if ($entries === []) {
            throw new PredictionDispatchException("Sequence {$sequenceUuid} has no eligible imagery.");
        }

        return [
            'params' => [$entries],
            'sequence_uuid' => $sequenceUuid,
            'config_url' => $configUrl,
            'callback' => false,
        ];
    }

    private function configUrl(string $sequenceUuid): string
    {
        $connectionName = config('mapilio.legacy_database_connection');
        $connection = DB::connection($connectionName);
        $detail = $connection->table('default_mapilio_sequence_detail')
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->first(['project_key']);

        if (
            $detail?->project_key
            && Schema::connection($connectionName)->hasTable('default_projects_projects')
            && Schema::connection($connectionName)->hasColumn('default_projects_projects', 'config_url')
        ) {
            $projectConfig = $connection->table('default_projects_projects')
                ->where('project_key', $detail->project_key)
                ->whereNull('deleted_at')
                ->value('config_url');

            if (is_string($projectConfig) && trim($projectConfig) !== '') {
                return trim($projectConfig);
            }
        }

        return trim((string) config('mapilio.ai_prediction.config_url'));
    }

    private function request(string $sequenceUuid): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->connectTimeout((int) config('mapilio.ai_prediction.connect_timeout', 5))
            ->timeout((int) config('mapilio.ai_prediction.timeout', 30))
            ->withHeaders([
                'Idempotency-Key' => "prediction:{$sequenceUuid}",
            ]);

        $token = trim((string) config('mapilio.ai_prediction.token'));

        return $token === '' ? $request : $request->withToken($token);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function onlyExistingColumns(string $table, array $values): array
    {
        $schema = Schema::connection(config('mapilio.legacy_database_connection'));

        return array_filter(
            $values,
            static fn (string $column): bool => $schema->hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function updateSequenceStatus(ConnectionInterface $connection, string $sequenceUuid, array $values): void
    {
        $values = $this->onlyExistingColumns('default_mapilio_sequence_detail', $values);

        if ($values === []) {
            return;
        }

        $connection->table('default_mapilio_sequence_detail')
            ->where('sequence_uuid', $sequenceUuid)
            ->update($values);
    }
}
