<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_routing_policies')) {
            Schema::create('ai_routing_policies', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('strategy')->default('free_first'); // strict_fallback, round_robin, weighted, least_recently_used, lowest_cost, free_first, quality_first
                $table->boolean('requires_tool_calling')->default(false);
                $table->boolean('requires_vision')->default(false);
                $table->unsignedInteger('max_latency_ms')->nullable();
                $table->decimal('max_cost_per_turn_usd', 10, 4)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('ai_routing_targets')) {
            Schema::create('ai_routing_targets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('policy_id')->constrained('ai_routing_policies')->cascadeOnDelete();
                $table->foreignId('model_id')->constrained('ai_provider_models')->cascadeOnDelete();
                $table->integer('priority')->default(10);
                $table->unsignedInteger('weight')->default(1);
                $table->boolean('is_fallback')->default(false);
                $table->timestamps();

                $table->unique(['policy_id', 'model_id']);
                $table->index(['policy_id', 'priority']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_routing_targets');
        Schema::dropIfExists('ai_routing_policies');
    }
};
