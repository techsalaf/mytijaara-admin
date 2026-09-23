<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_routing_attempts')) {
            Schema::create('ai_routing_attempts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('conversation_id')->nullable();
                $table->foreignId('policy_id')->nullable()->constrained('ai_routing_policies')->nullOnDelete();
                $table->foreignId('connection_id')->nullable()->constrained('ai_provider_connections')->nullOnDelete();
                $table->foreignId('model_id')->nullable()->constrained('ai_provider_models')->nullOnDelete();
                $table->unsignedInteger('attempt_number')->default(1);
                $table->string('status'); // success, rate_limited, auth_failed, timeout, error
                $table->text('error_message')->nullable();
                $table->unsignedInteger('latency_ms')->nullable();
                $table->unsignedInteger('prompt_tokens')->default(0);
                $table->unsignedInteger('completion_tokens')->default(0);
                $table->decimal('cost_usd', 10, 6)->default(0);
                $table->timestamp('created_at')->useCurrent();

                $table->index(['conversation_id', 'created_at']);
                $table->index(['connection_id', 'status', 'created_at']);
            });
        }

        if (!Schema::hasTable('ai_usage_records')) {
            Schema::create('ai_usage_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('ai_provider_connections')->cascadeOnDelete();
                $table->foreignId('model_id')->nullable()->constrained('ai_provider_models')->nullOnDelete();
                $table->date('usage_date');
                $table->unsignedInteger('total_requests')->default(0);
                $table->unsignedInteger('successful_requests')->default(0);
                $table->unsignedInteger('failed_requests')->default(0);
                $table->unsignedBigInteger('total_prompt_tokens')->default(0);
                $table->unsignedBigInteger('total_completion_tokens')->default(0);
                $table->decimal('total_cost_usd', 12, 6)->default(0);
                $table->timestamps();

                $table->unique(['connection_id', 'model_id', 'usage_date']);
                $table->index(['usage_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_records');
        Schema::dropIfExists('ai_routing_attempts');
    }
};
