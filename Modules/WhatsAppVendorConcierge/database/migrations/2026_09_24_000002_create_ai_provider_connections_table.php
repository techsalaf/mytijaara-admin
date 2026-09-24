<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_provider_connections')) {
            Schema::dropIfExists('ai_provider_connections');
        }

        Schema::create('ai_provider_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('definition_id')->constrained('ai_provider_definitions')->cascadeOnDelete();
            $table->string('name');
            $table->text('credentials'); // Encrypted JSON payload containing keys/secrets
            $table->string('base_url_override')->nullable();
            $table->json('auth_state')->nullable(); // Token expiry, refresh metadata
            $table->boolean('is_active')->default(true);
            $table->string('status', 32)->default('healthy'); // healthy, degraded, rate_limited, cooldown, auth_failed, budget_exhausted, disabled
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamp('rate_limit_reset_at')->nullable();
            $table->decimal('daily_budget_usd', 10, 4)->nullable();
            $table->decimal('monthly_budget_usd', 10, 4)->nullable();
            $table->decimal('current_day_cost_usd', 10, 4)->default(0);
            $table->decimal('current_month_cost_usd', 10, 4)->default(0);
            $table->date('cost_reset_day')->nullable();
            $table->string('cost_reset_month', 7)->nullable();
            $table->string('selection_mode', 32)->default('all_compatible'); // all_compatible, manual, auto_include_free
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_connections');
    }
};
