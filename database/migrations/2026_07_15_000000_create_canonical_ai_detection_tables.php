<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_detection_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('callback_receipt_id')
                ->constrained('ai_prediction_callback_receipts')
                ->cascadeOnDelete();
            $table->string('response_id');
            $table->string('sequence_uuid');
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('organization_key')->nullable();
            $table->string('project_key')->nullable();
            $table->unsignedInteger('source_index');
            $table->string('class_code', 100);
            $table->double('confidence');
            $table->double('longitude');
            $table->double('latitude');
            $table->json('geometry');
            $table->double('width');
            $table->double('height');
            $table->double('area');
            $table->boolean('verified');
            $table->json('attributes')->nullable();
            $table->timestamps();

            $table->unique(['callback_receipt_id', 'source_index'], 'ai_detection_feature_source_unique');
            $table->index(['sequence_uuid', 'class_code']);
            $table->index(['response_id', 'source_index']);
        });

        Schema::create('ai_detection_observations', function (Blueprint $table): void {
            $table->id();
            $table->string('response_id');
            $table->string('sequence_uuid');
            $table->string('object_key');
            $table->unsignedBigInteger('imagery_id');
            $table->double('x_min');
            $table->double('y_min');
            $table->double('x_max');
            $table->double('y_max');
            $table->double('score');
            $table->json('segmentation')->nullable();
            $table->timestamps();

            $table->unique(['response_id', 'object_key'], 'ai_detection_observation_object_unique');
            $table->index(['sequence_uuid', 'imagery_id']);
        });

        Schema::create('ai_detection_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('detection_feature_id')
                ->constrained('ai_detection_features')
                ->cascadeOnDelete();
            $table->foreignId('observation_1_id')
                ->constrained('ai_detection_observations')
                ->restrictOnDelete();
            $table->foreignId('observation_2_id')
                ->constrained('ai_detection_observations')
                ->restrictOnDelete();
            $table->unsignedInteger('source_index');
            $table->double('longitude');
            $table->double('latitude');
            $table->json('geometry');
            $table->double('score');
            $table->timestamps();

            $table->unique(['detection_feature_id', 'source_index'], 'ai_detection_match_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_detection_matches');
        Schema::dropIfExists('ai_detection_observations');
        Schema::dropIfExists('ai_detection_features');
    }
};
