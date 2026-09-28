<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('whatsapp_vendor_preferences', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_vendor_preferences', 'opt_in_recovery_nudges')) {
                $table->boolean('opt_in_recovery_nudges')->default(false)->after('opt_in_stock_alerts');
            }
            if (!Schema::hasColumn('whatsapp_vendor_preferences', 'opt_in_weekly_digest')) {
                $table->boolean('opt_in_weekly_digest')->default(false)->after('opt_in_recovery_nudges');
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_vendor_preferences', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_vendor_preferences', 'opt_in_weekly_digest')) $table->dropColumn('opt_in_weekly_digest');
            if (Schema::hasColumn('whatsapp_vendor_preferences', 'opt_in_recovery_nudges')) $table->dropColumn('opt_in_recovery_nudges');
        });
    }
};
