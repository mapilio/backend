<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prediction_status_projections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('callback_receipt_id')
                ->unique()
                ->constrained('ai_prediction_callback_receipts')
                ->cascadeOnDelete();
            $table->string('response_id');
            $table->string('sequence_uuid');
            $table->string('response_status', 32);
            $table->string('projection_status', 32)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('projected_at')->nullable();
            $table->timestamps();

            $table->index(['projection_status', 'updated_at']);
        });

        Schema::create('geospatial_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('callback_receipt_id')
                ->unique()
                ->constrained('ai_prediction_callback_receipts')
                ->cascadeOnDelete();
            $table->string('sequence_uuid');
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('target', 100);
            $table->string('target_layer')->nullable();
            $table->unsignedInteger('feature_count')->default(0);
            $table->string('publication_status', 32)->default('blocked');
            $table->text('status_reason')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'target'], 'geospatial_publication_source_unique');
            $table->index(['publication_status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geospatial_publications');
        Schema::dropIfExists('ai_prediction_status_projections');
    }
};
