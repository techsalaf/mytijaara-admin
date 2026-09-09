<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_flows', function (Blueprint $table) {
            $table->id();
            $table->string('flow_id')->unique()->comment('Meta Flow ID');
            $table->string('name');
            $table->string('version')->default('1.0');
            $table->json('schema')->nullable()->comment('Flow JSON schema');
            $table->json('screens')->nullable()->comment('Screen definitions');
            $table->enum('status', ['draft', 'published', 'deprecated'])->default('draft');
            $table->json('validation_rules')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_flows');
    }
};