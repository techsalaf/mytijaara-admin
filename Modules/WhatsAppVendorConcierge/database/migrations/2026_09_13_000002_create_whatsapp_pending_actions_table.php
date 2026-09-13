<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_pending_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('whatsapp_contacts')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->string('action_type', 80);
            $table->json('payload');
            $table->string('payload_hash', 64);
            $table->text('preview');
            $table->string('action_token', 64)->unique();
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('result_metadata')->nullable();
            $table->timestamps();
            $table->index(['contact_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_pending_actions');
    }
};
