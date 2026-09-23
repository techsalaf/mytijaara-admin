<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_provider_definitions')) {
            Schema::create('ai_provider_definitions', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique();
                $table->string('name');
                $table->string('adapter_class');
                $table->string('default_base_url')->nullable();
                $table->string('auth_type')->default('api_key');
                $table->boolean('supports_model_discovery')->default(false);
                $table->boolean('supports_tool_calling')->default(true);
                $table->boolean('supports_vision')->default(false);
                $table->boolean('supports_streaming')->default(false);
                $table->json('catalogue_models')->nullable();
                $table->string('documentation_url')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_definitions');
    }
};
