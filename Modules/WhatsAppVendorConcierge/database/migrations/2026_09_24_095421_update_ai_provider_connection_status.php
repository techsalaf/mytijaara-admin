<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_provider_connections', function (Blueprint $table) {
            // Change default of status column
            $table->string('status', 32)->default('unverified')->change();
        });

        // Update existing rows that were incorrectly marked as 'healthy' without passing inference
        DB::table('ai_provider_connections')->where('status', 'healthy')->update(['status' => 'unverified']);
    }

    public function down(): void
    {
        Schema::table('ai_provider_connections', function (Blueprint $table) {
            $table->string('status', 32)->default('healthy')->change();
        });
    }
};
