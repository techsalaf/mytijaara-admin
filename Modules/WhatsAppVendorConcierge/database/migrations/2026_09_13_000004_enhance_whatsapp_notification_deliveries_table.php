<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_notification_deliveries', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_notification_deliveries', 'attempt_count')) {
                $table->unsignedInteger('attempt_count')->default(0)->after('status');
            }
            if (!Schema::hasColumn('whatsapp_notification_deliveries', 'claimed_at')) {
                $table->timestamp('claimed_at')->nullable()->after('attempt_count');
            }
            if (!Schema::hasColumn('whatsapp_notification_deliveries', 'claim_expires_at')) {
                $table->timestamp('claim_expires_at')->nullable()->after('claimed_at');
            }
            if (!Schema::hasColumn('whatsapp_notification_deliveries', 'channel')) {
                $table->string('channel', 20)->nullable()->after('claim_expires_at');
            }
            if (!Schema::hasColumn('whatsapp_notification_deliveries', 'error_code')) {
                $table->string('error_code', 50)->nullable()->after('channel');
            }
            if (!Schema::hasColumn('whatsapp_notification_deliveries', 'idempotency_key')) {
                $table->string('idempotency_key', 64)->nullable()->after('error_code')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_notification_deliveries', function (Blueprint $table) {
            $table->dropColumn([
                'attempt_count',
                'claimed_at',
                'claim_expires_at',
                'channel',
                'error_code',
                'idempotency_key',
            ]);
        });
    }
};
