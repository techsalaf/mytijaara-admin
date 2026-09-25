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
        Schema::create('whatsapp_resume_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('audience_criteria')->nullable(); // For Audience selection
            $table->string('meta_template_name')->nullable(); // Compliance safeguards
            $table->string('status')->default('draft'); // draft, active, completed
            $table->integer('sent_count')->default(0);
            $table->integer('resumed_count')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_resume_campaigns');
    }
};
