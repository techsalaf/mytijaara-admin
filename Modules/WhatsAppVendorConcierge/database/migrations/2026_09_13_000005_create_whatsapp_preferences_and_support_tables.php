<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_vendor_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->unique()->constrained('whatsapp_contacts')->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->boolean('opt_in_order_alerts')->default(true);
            $table->boolean('opt_in_status_alerts')->default(true);
            $table->boolean('opt_in_stock_alerts')->default(true);
            $table->boolean('is_paused')->default(false);
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->string('locale', 10)->default('en');
            $table->json('consent_audit_log')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_support_cases', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_id', 32)->unique();
            $table->foreignId('contact_id')->constrained('whatsapp_contacts')->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('category', 50)->default('general');
            $table->string('priority', 20)->default('medium');
            $table->string('status', 20)->default('open');
            $table->string('subject', 255);
            $table->text('safe_summary')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamp('sla_expires_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'priority']);
        });

        Schema::create('whatsapp_ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('whatsapp_contacts')->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('model', 50)->default('claude-code-local');
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->double('estimated_cost', 10, 6)->default(0);
            $table->boolean('is_fallback')->default(false);
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_ai_usage_logs');
        Schema::dropIfExists('whatsapp_support_cases');
        Schema::dropIfExists('whatsapp_vendor_preferences');
    }
};
