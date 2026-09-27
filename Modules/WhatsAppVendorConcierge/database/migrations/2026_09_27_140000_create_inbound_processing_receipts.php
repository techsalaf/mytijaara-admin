<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('ai_provider_connections', 'verification')) Schema::table('ai_provider_connections', fn (Blueprint $table) => $table->json('verification')->nullable());
        if (!Schema::hasTable('whatsapp_inbound_receipts')) Schema::create('whatsapp_inbound_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('whatsapp_message_id')->unique();
            $table->unsignedBigInteger('message_id')->nullable()->index();
            $table->string('phase', 32)->default('received')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_type')->nullable();
            $table->string('state_version', 64)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('whatsapp_inbound_receipts'); Schema::table('ai_provider_connections', fn (Blueprint $table) => $table->dropColumn('verification')); }
};
