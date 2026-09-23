<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('whatsapp_ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g. "OpenAI", "DeepSeek", "Groq"
            $table->string('driver')->default('openai'); // Usually 'openai' or 'anthropic' for laravel/ai
            $table->string('base_url')->nullable(); // For OpenAI compatible APIs (DeepSeek, etc)
            $table->string('api_key'); // The provider API key
            $table->string('model'); // e.g. "gpt-4o", "deepseek-chat"
            $table->integer('priority')->default(0); // Lower number = higher priority
            $table->boolean('is_active')->default(true); // Can toggle on/off
            $table->string('status')->default('working'); // working, failed, rate_limited
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_ai_providers');
    }
};
