<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contact_id')->index();
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->enum('state', [
                'new',
                'welcome',
                'onboarding_active',
                'onboarding_paused',
                'onboarding_completed',
                'ai_active',
                'human_handoff',
                'closed',
                'expired'
            ])->default('new');
            $table->string('current_intent')->nullable()->comment('Current user intent');
            $table->string('current_step')->nullable()->comment('Current onboarding step');
            $table->json('context')->nullable()->comment('Conversation context data');
            $table->json('collected_data')->nullable()->comment('Data collected during onboarding');
            $table->unsignedBigInteger('onboarding_session_id')->nullable()->index();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('contact_id')->references('id')->on('whatsapp_contacts')->onDelete('cascade');
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('set null');
            $table->index(['vendor_id', 'state']);
            $table->index(['state', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversations');
    }
};