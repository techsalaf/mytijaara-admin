<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('onboarding_session_id')->index();
            $table->unsignedBigInteger('contact_id')->index();
            $table->string('event_type', 100)->comment('onboarding_started, business_name_submitted, category_selected, location_shared, contact_submitted, documents_uploaded, review_started, application_submitted, application_approved, application_rejected, onboarding_abandoned, step_completed, step_failed, ai_interaction, human_handoff');
            $table->string('step', 100)->nullable()->comment('Which step this event relates to');
            $table->json('payload')->nullable()->comment('Event data');
            $table->json('metadata')->nullable()->comment('Additional context');
            $table->unsignedBigInteger('duration_ms')->nullable()->comment('Time spent on step');
            $table->timestamps();

            $table->foreign('onboarding_session_id')->references('id')->on('onboarding_sessions')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('whatsapp_contacts')->onDelete('cascade');
            $table->index(['event_type', 'created_at']);
            $table->index(['contact_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_events');
    }
};