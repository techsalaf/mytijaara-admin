<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('concierge_recovery_audits')) {
            Schema::create('concierge_recovery_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('conversation_id')->nullable()->index();
                $table->unsignedBigInteger('contact_id')->nullable()->index();
                $table->string('action', 64)->index();
                $table->string('initiated_by', 32)->default('admin')->index(); // admin, system_automated, cli
                $table->unsignedBigInteger('admin_id')->nullable();
                $table->string('previous_state', 64)->nullable();
                $table->string('proposed_state', 64)->nullable();
                $table->string('previous_step', 64)->nullable();
                $table->boolean('is_dry_run')->default(false)->index();
                $table->string('status', 32)->default('success')->index(); // success, failed, skipped
                $table->text('reason')->nullable();
                $table->json('details')->nullable();
                $table->string('correlation_id', 64)->nullable()->index();
                $table->timestamps();

                $table->index(['conversation_id', 'created_at']);
                $table->index(['action', 'status', 'created_at']);
            });
        }

        if (!Schema::hasTable('concierge_health_checks')) {
            Schema::create('concierge_health_checks', function (Blueprint $table) {
                $table->id();
                $table->string('check_type', 32)->default('scheduled')->index(); // scheduled, on_demand, cli
                $table->integer('total_active_conversations')->default(0);
                $table->integer('waiting_for_concierge')->default(0);
                $table->integer('waiting_for_user')->default(0);
                $table->integer('in_human_handoff')->default(0);
                $table->integer('stale_human_handoff')->default(0);
                $table->integer('silenced_count')->default(0);
                $table->integer('stuck_count')->default(0);
                $table->integer('failed_sends_count')->default(0);
                $table->integer('auto_recovered_count')->default(0);
                $table->integer('issues_requiring_human')->default(0);
                $table->integer('issues_requiring_code')->default(0);
                $table->json('summary')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('concierge_recovery_audits');
        Schema::dropIfExists('concierge_health_checks');
    }
};
