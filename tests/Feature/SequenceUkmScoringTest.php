<?php

namespace Tests\Feature;

use App\Domain\ImagerySequences\Actions\CalculateSequenceUkmScores;
use App\Domain\ImagerySequences\Actions\UkmScoringException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SequenceUkmScoringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-15 12:00:00');
        Config::set('mapilio.ukm_scoring.enabled', true);
        Config::set('mapilio.ukm_scoring.history_months', 6);
        Config::set('mapilio.ukm_scoring.heading_tolerance_degrees', 45);
        Config::set('mapilio.ukm_scoring.min_distance_meters', 1);
        Config::set('mapilio.ukm_scoring.max_distance_meters', 40);
        Config::set('mapilio.ukm_scoring.max_score', 5);
        Config::set('mapilio.ukm_scoring.max_points_per_sequence', 100);

        $this->createTables();
        $this->insertSequence('current-sequence');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_scores_nearest_historical_imagery_and_rewards_no_neighbor(): void
    {
        $this->insertImagery(1, 'current-sequence', '2026-07-01 10:00:00', 0, 40.0, 29.0);
        $this->insertImagery(2, 'current-sequence', '2026-07-01 10:00:01', 180, 41.0, 30.0);
        $this->insertImagery(3, 'current-sequence', '2026-07-01 10:00:02', 350, 42.0, 31.0);

        $this->insertImagery(10, 'history-a', '2026-06-01 10:00:00', 10, 40.00018, 29.0);
        $this->insertImagery(11, 'history-b', '2026-06-01 10:00:00', 10, 42.00009, 31.0);

        // Nearby rows that must not qualify for the second source.
        $this->insertImagery(12, 'current-sequence', '2026-06-01 10:00:00', 180, 41.00001, 30.0, score: 1);
        $this->insertImagery(13, 'history-c', '2026-12-01 10:00:00', 180, 41.00001, 30.0);
        $this->insertImagery(14, 'history-c', '2025-01-01 10:00:00', 180, 41.00001, 30.0);
        $this->insertImagery(15, 'history-c', '2026-06-01 10:00:00', 90, 41.00001, 30.0);
        $this->insertImagery(16, 'history-c', '2026-06-01 10:00:00', 180, 41.00001, 30.0, anomaly: true);

        $result = app(CalculateSequenceUkmScores::class)->calculate('current-sequence');

        $this->assertSame([
            'status' => 'completed',
            'processed' => 3,
            'no_neighbor' => 1,
        ], $result);

        $first = Schema::getConnection()->table('default_mapilio_imagery')->find(1);
        $second = Schema::getConnection()->table('default_mapilio_imagery')->find(2);
        $third = Schema::getConnection()->table('default_mapilio_imagery')->find(3);

        $this->assertIsObject($first);
        $this->assertIsObject($second);
        $this->assertIsObject($third);
        $this->assertEqualsWithDelta(20.0, (float) $first->ukm_closest_distance, 0.2);
        $this->assertSame(2.5, (float) $first->ukm_score);
        $this->assertSame(40.0, (float) $second->ukm_closest_distance);
        $this->assertSame(5.0, (float) $second->ukm_score);
        $this->assertEqualsWithDelta(10.0, (float) $third->ukm_closest_distance, 0.2);
        $this->assertSame(1.3, (float) $third->ukm_score);
        $this->assertSame(2, (int) $third->ukm_status);
        $this->assertNull($third->ukm_status_message);
    }

    public function test_score_keeps_legacy_age_divisor(): void
    {
        $this->insertImagery(1, 'current-sequence', '2023-07-01 10:00:00', 0, 40.0, 29.0);
        $this->insertImagery(10, 'history-a', '2023-06-01 10:00:00', 0, 40.00018, 29.0);

        app(CalculateSequenceUkmScores::class)->calculate('current-sequence');

        $row = Schema::getConnection()->table('default_mapilio_imagery')->find(1);

        $this->assertIsObject($row);
        $this->assertEqualsWithDelta(2.5 / 3, (float) $row->ukm_score, 0.0001);
    }

    public function test_completed_scores_are_idempotent(): void
    {
        $this->insertImagery(1, 'current-sequence', '2026-07-01 10:00:00', 0, 40.0, 29.0);

        $first = app(CalculateSequenceUkmScores::class)->calculate('current-sequence');
        Schema::getConnection()->table('default_mapilio_imagery')
            ->where('id', 1)
            ->update(['ukm_score' => 99]);
        $second = app(CalculateSequenceUkmScores::class)->calculate('current-sequence');

        $this->assertSame(1, $first['processed']);
        $this->assertSame(0, $second['processed']);
        $this->assertDatabaseHas('default_mapilio_imagery', [
            'id' => 1,
            'ukm_score' => 99,
        ]);
    }

    public function test_invalid_pending_imagery_marks_safe_error_state(): void
    {
        $this->insertImagery(1, 'current-sequence', '2026-07-01 10:00:00', 0, 999, 29.0);

        try {
            app(CalculateSequenceUkmScores::class)->calculate('current-sequence');
            $this->fail('Invalid imagery should fail UKM scoring.');
        } catch (UkmScoringException $exception) {
            $this->assertSame(
                'UKM scoring requires valid coordinates, heading, geometry, and capture time for every pending image.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('default_mapilio_imagery', [
            'id' => 1,
            'ukm_score' => null,
            'ukm_status' => 1,
            'ukm_status_message' => 'UKM scoring requires valid coordinates, heading, geometry, and capture time for every pending image.',
        ]);
    }

    public function test_disabled_scoring_has_no_side_effects(): void
    {
        $this->insertImagery(1, 'current-sequence', '2026-07-01 10:00:00', 0, 40.0, 29.0);
        Config::set('mapilio.ukm_scoring.enabled', false);

        $result = app(CalculateSequenceUkmScores::class)->calculate('current-sequence');

        $this->assertSame([
            'status' => 'disabled',
            'processed' => 0,
            'no_neighbor' => 0,
        ], $result);
        $this->assertDatabaseHas('default_mapilio_imagery', [
            'id' => 1,
            'ukm_score' => null,
            'ukm_status' => null,
        ]);
    }

    private function createTables(): void
    {
        Schema::create('default_mapilio_sequence_detail', function ($table): void {
            $table->id();
            $table->timestamp('deleted_at')->nullable();
            $table->string('sequence_uuid');
            $table->boolean('anomaly')->default(false);
        });

        Schema::create('default_mapilio_imagery', function ($table): void {
            $table->id();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
            $table->string('sequence_uuid');
            $table->timestamp('capture_time')->nullable();
            $table->double('heading')->nullable();
            $table->double('latitude')->nullable();
            $table->double('longitude')->nullable();
            $table->boolean('anomaly')->default(false);
            $table->double('ukm_closest_distance')->nullable();
            $table->double('ukm_score')->nullable();
            $table->integer('ukm_status')->nullable();
            $table->text('ukm_status_message')->nullable();
        });
    }

    private function insertSequence(string $sequenceUuid): void
    {
        Schema::getConnection()->table('default_mapilio_sequence_detail')->insert([
            'sequence_uuid' => $sequenceUuid,
            'deleted_at' => null,
            'anomaly' => false,
        ]);
    }

    private function insertImagery(
        int $id,
        string $sequenceUuid,
        string $captureTime,
        float $heading,
        float $latitude,
        float $longitude,
        ?float $score = null,
        bool $anomaly = false,
    ): void {
        Schema::getConnection()->table('default_mapilio_imagery')->insert([
            'id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
            'sequence_uuid' => $sequenceUuid,
            'capture_time' => $captureTime,
            'heading' => $heading,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'anomaly' => $anomaly,
            'ukm_closest_distance' => $score === null ? null : 1,
            'ukm_score' => $score,
            'ukm_status' => $score === null ? null : 2,
            'ukm_status_message' => null,
        ]);
    }
}
