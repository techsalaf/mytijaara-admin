<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_provider_models')) {
            Schema::create('ai_provider_models', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('ai_provider_connections')->cascadeOnDelete();
                $table->string('model_id', 128);
                $table->string('name');
                $table->boolean('is_enabled')->default(true);
                $table->boolean('supports_tool_calling')->default(false);
                $table->boolean('supports_vision')->default(false);
                $table->boolean('is_free_tier')->default(false);
                $table->unsignedInteger('context_window')->nullable();
                $table->unsignedInteger('max_output_tokens')->nullable();
                $table->decimal('cost_per_million_input', 10, 4)->default(0);
                $table->decimal('cost_per_million_output', 10, 4)->default(0);
                $table->integer('priority')->default(10);
                $table->unsignedInteger('weight')->default(1);
                $table->json('capabilities_override')->nullable();
                $table->timestamps();

                $table->unique(['connection_id', 'model_id']);
                $table->index(['is_enabled', 'priority']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_models');
    }
};
