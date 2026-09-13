<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('notification_type', 40);
            $table->string('state_version', 64);
            $table->string('status', 20)->default('sending');
            $table->string('provider_message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'notification_type', 'state_version'], 'wa_notification_dedupe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_notification_deliveries');
    }
};
