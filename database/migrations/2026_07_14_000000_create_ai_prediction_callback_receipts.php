<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prediction_callback_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('response_id');
            $table->string('response_status', 32);
            $table->char('payload_hash', 64);
            $table->char('fingerprint', 64)->unique();
            $table->longText('encrypted_payload');
            $table->unsignedInteger('result_feature_count')->default(0);
            $table->string('processing_status', 48)->default('received');
            $table->text('processing_error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['response_id', 'response_status']);
            $table->index(['processing_status', 'received_at']);
        });

        Schema::create('ai_prediction_callback_nonces', function (Blueprint $table): void {
            $table->id();
            $table->string('nonce', 128)->unique();
            $table->timestamp('signed_at');
            $table->timestamp('expires_at')->index();
            $table->foreignId('callback_receipt_id')
                ->nullable()
                ->constrained('ai_prediction_callback_receipts')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prediction_callback_nonces');
        Schema::dropIfExists('ai_prediction_callback_receipts');
    }
};
