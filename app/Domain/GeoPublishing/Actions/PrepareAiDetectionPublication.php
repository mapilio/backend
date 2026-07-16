<?php

namespace App\Domain\GeoPublishing\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PrepareAiDetectionPublication
{
    public function prepare(int $publicationId): bool
    {
        if (! config('mapilio.geo_publication.preparation_enabled')) {
            return false;
        }

        $publication = DB::table('geospatial_publications')->find($publicationId);

        if ($publication === null) {
            throw new GeoPublicationException("Geo publication {$publicationId} was not found.");
        }

        if (! is_object($publication)) {
            throw new GeoPublicationException('Geo publication has an invalid database representation.');
        }

        if (in_array($publication->publication_status, ['ready', 'published'], true)) {
            return false;
        }

        $expectedCount = (int) $publication->feature_count;
        $actualCount = 0;
        $missingViewCount = 0;
        $invalidGeometryCount = 0;

        DB::table('geospatial_publications')
            ->where('id', $publicationId)
            ->update([
                'publication_status' => 'preparing',
                'attempts' => DB::raw('attempts + 1'),
                'status_reason' => null,
                'updated_at' => now(),
            ]);

        try {
            $view = $this->viewName();
            $targetLayer = $this->targetLayer();
            $receipt = DB::table('ai_prediction_callback_receipts')->find($publication->callback_receipt_id);

            if (! is_object($receipt) || $receipt->processing_status !== 'processed' || $receipt->response_status !== 'SUCCESS') {
                throw new GeoPublicationException('Geo publication source receipt is not a processed successful result.');
            }

            $actualCount = DB::table('ai_detection_features')
                ->where('callback_receipt_id', $publication->callback_receipt_id)
                ->count();

            if ($expectedCount !== (int) $receipt->result_feature_count || $expectedCount !== $actualCount) {
                throw new GeoPublicationException('Geo publication feature counts do not reconcile.');
            }

            $sequenceMismatchCount = DB::table('ai_detection_features')
                ->where('callback_receipt_id', $publication->callback_receipt_id)
                ->where('sequence_uuid', '!=', $publication->sequence_uuid)
                ->count();

            $invalidGeometryCount = DB::table('ai_detection_features')
                ->where('callback_receipt_id', $publication->callback_receipt_id)
                ->where(function ($query): void {
                    $query
                        ->whereNull('longitude')
                        ->orWhereNull('latitude')
                        ->orWhere('longitude', '<', -180)
                        ->orWhere('longitude', '>', 180)
                        ->orWhere('latitude', '<', -90)
                        ->orWhere('latitude', '>', 90);
                })
                ->count();

            if ($sequenceMismatchCount > 0 || $invalidGeometryCount > 0) {
                throw new GeoPublicationException('Geo publication contains invalid sequence ownership or coordinates.');
            }

            DB::table($view)->limit(1)->get(['id']);

            $missingViewCount = DB::table('ai_detection_features as source')
                ->leftJoin("{$view} as projection", 'projection.id', '=', 'source.id')
                ->where('source.callback_receipt_id', $publication->callback_receipt_id)
                ->where(function ($query): void {
                    $query->whereNull('projection.id')->orWhereNull('projection.geom');
                })
                ->count();

            if ($missingViewCount > 0) {
                throw new GeoPublicationException('Geo publication view is missing canonical features or geometry.');
            }

            DB::transaction(function () use (
                $publicationId,
                $expectedCount,
                $actualCount,
                $missingViewCount,
                $invalidGeometryCount,
                $targetLayer,
            ): void {
                $this->recordCheck(
                    $publicationId,
                    'passed',
                    $expectedCount,
                    $actualCount,
                    $missingViewCount,
                    $invalidGeometryCount,
                    null,
                );

                DB::table('geospatial_publications')->where('id', $publicationId)->update([
                    'target_layer' => $targetLayer,
                    'publication_status' => 'ready',
                    'status_reason' => 'Database projection reconciled; GeoServer layer activation is pending.',
                    'prepared_at' => now(),
                    'reconciled_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            return true;
        } catch (Throwable $exception) {
            $message = $exception instanceof GeoPublicationException
                ? Str::limit($exception->getMessage(), 1000, '')
                : 'Geo publication preparation could not be completed.';

            DB::transaction(function () use (
                $publicationId,
                $expectedCount,
                $actualCount,
                $missingViewCount,
                $invalidGeometryCount,
                $message,
            ): void {
                $this->recordCheck(
                    $publicationId,
                    'failed',
                    $expectedCount,
                    $actualCount,
                    $missingViewCount,
                    $invalidGeometryCount,
                    $message,
                );

                DB::table('geospatial_publications')->where('id', $publicationId)->update([
                    'publication_status' => 'error',
                    'status_reason' => $message,
                    'updated_at' => now(),
                ]);
            });

            if ($exception instanceof GeoPublicationException) {
                throw $exception;
            }

            throw new GeoPublicationException($message, previous: $exception);
        }
    }

    private function viewName(): string
    {
        $view = (string) config('mapilio.geo_publication.view', 'mapilio_ai_features_v1');

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $view)) {
            throw new GeoPublicationException('Geo publication view configuration is invalid.');
        }

        return $view;
    }

    private function targetLayer(): string
    {
        $layer = (string) config('mapilio.geo_publication.layer', 'mapilio:ai_features_v1');

        if (! preg_match('/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/', $layer)) {
            throw new GeoPublicationException('Geo publication layer configuration is invalid.');
        }

        return $layer;
    }

    private function recordCheck(
        int $publicationId,
        string $status,
        int $expectedCount,
        int $actualCount,
        int $missingViewCount,
        int $invalidGeometryCount,
        ?string $error,
    ): void {
        DB::table('geospatial_publication_checks')->insert([
            'geospatial_publication_id' => $publicationId,
            'check_status' => $status,
            'expected_feature_count' => $expectedCount,
            'actual_feature_count' => $actualCount,
            'missing_view_feature_count' => $missingViewCount,
            'invalid_geometry_count' => $invalidGeometryCount,
            'error' => $error,
            'checked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
