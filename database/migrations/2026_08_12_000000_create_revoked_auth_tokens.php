<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Denylist of revoked mobile auth tokens.
     *
     * Only revoked tokens are stored, and only until they would have expired
     * anyway, so the table stays proportional to revocations rather than to
     * issued tokens. It lives on the modern connection: the legacy schema is
     * still the migration source and gains no new tables.
     */
    public function up(): void
    {
        Schema::create('revoked_auth_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('jti', 64)->unique();
            $table->unsignedBigInteger('subject')->index();
            $table->string('token_type', 16);
            $table->string('reason', 64)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revoked_auth_tokens');
    }
};
