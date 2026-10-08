<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_vendor_flow_sync', fn (Blueprint $t) => $t->text('last_error_metadata')->nullable());
        Schema::table('wa_vendor_flow_sessions', fn (Blueprint $t) => $t->dateTime('credential_setup_completed_at')->nullable());
        Schema::create('wa_flow_definition_revisions', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->string('version', 80)->primary();
            $t->char('asset_hash', 64);
            $t->unsignedBigInteger('admin_id');
            $t->text('presentation_changes');
            $t->dateTime('created_at');
        });
        Schema::create('wa_flow_control_settings', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->string('key', 80)->primary();
            $t->text('value');
            $t->timestamps();
        });
        DB::table('wa_flow_control_settings')->insert(['key' => 'control_gate', 'value' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('wa_flow_control_permissions', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->unsignedBigInteger('role_id');
            $t->string('permission', 60);
            $t->primary(['role_id', 'permission']);
        });
        Schema::create('wa_flow_control_operations', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('admin_id');
            $t->string('action', 60);
            $t->string('target', 100)->nullable();
            $t->char('idempotency_key', 64)->unique();
            $t->string('state', 30)->default('queued');
            $t->text('context');
            $t->text('result')->nullable();
            $t->timestamps();
            $t->index(['state', 'created_at']);
        });
        Schema::create('wa_flow_control_audits', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->unsignedBigInteger('admin_id');
            $t->uuid('operation_id')->nullable();
            $t->string('action', 60);
            $t->string('target', 100)->nullable();
            $t->string('outcome', 30);
            $t->text('safe_values');
            $t->dateTime('created_at');
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER wa_flow_audit_no_update BEFORE UPDATE ON wa_flow_control_audits BEGIN SELECT RAISE(ABORT, 'Immutable administrative audit'); END");
            DB::unprepared("CREATE TRIGGER wa_flow_audit_no_delete BEFORE DELETE ON wa_flow_control_audits BEGIN SELECT RAISE(ABORT, 'Immutable administrative audit'); END");
            DB::unprepared("CREATE TRIGGER wa_flow_revision_no_update BEFORE UPDATE ON wa_flow_definition_revisions BEGIN SELECT RAISE(ABORT, 'Immutable definition revision'); END");
            DB::unprepared("CREATE TRIGGER wa_flow_revision_no_delete BEFORE DELETE ON wa_flow_definition_revisions BEGIN SELECT RAISE(ABORT, 'Immutable definition revision'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            foreach (['UPDATE', 'DELETE'] as $verb) {
                DB::unprepared('CREATE TRIGGER wa_flow_audit_no_'.strtolower($verb)." BEFORE $verb ON wa_flow_control_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable administrative audit'");
                DB::unprepared('CREATE TRIGGER wa_flow_revision_no_'.strtolower($verb)." BEFORE $verb ON wa_flow_definition_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable definition revision'");
            }
        } else {
            throw new LogicException('Administrative evidence requires supported immutable storage.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve administrative audit evidence; destructive rollback is prohibited.');
    }
};
