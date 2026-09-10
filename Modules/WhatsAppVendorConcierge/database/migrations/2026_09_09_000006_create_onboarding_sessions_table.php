<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contact_id')->index();
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->string('flow_version', 20)->default('1.0');
            $table->enum('status', [
                'started',
                'business_basics',
                'category_selection',
                'location',
                'contact_info',
                'operating_hours',
                'documents',
                'review',
                'submitted',
                'approved',
                'rejected',
                'abandoned',
                'expired',
            ])->default('started');
            $table->json('collected_data')->nullable()->comment('All data collected during onboarding');
            $table->json('validation_errors')->nullable();
            $table->string('current_step')->nullable();
            $table->integer('step_attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('source', 50)->default('whatsapp')->comment('whatsapp, web, qr_code, referral');
            $table->string('attribution_code', 100)->nullable()->comment('Field agent/referral code');
            $table->timestamps();

            $table->foreign('contact_id')->references('id')->on('whatsapp_contacts')->onDelete('cascade');
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('set null');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('set null');
            $table->index(['contact_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_sessions');
    }
};