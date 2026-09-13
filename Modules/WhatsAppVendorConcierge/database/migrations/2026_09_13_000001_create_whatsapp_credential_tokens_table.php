<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_credential_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_session_id')->constrained('onboarding_sessions')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('purpose', 50);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->json('creation_metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_credential_tokens');
    }
};
