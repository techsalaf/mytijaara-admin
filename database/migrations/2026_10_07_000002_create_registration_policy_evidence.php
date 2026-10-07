<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Policy evidence immutability requires a reviewed database driver before schema creation.');
        }
        Schema::create('legal_policy_versions', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->string('policy', 20);
            $t->string('version', 191);
            $t->string('locale', 20);
            $t->char('content_hash', 64);
            $t->string('document_url', 2048);
            $t->string('storage_object', 1024);
            $t->dateTime('effective_at');
            $t->dateTime('retired_at')->nullable();
            $t->dateTime('created_at');
            $t->unique(['policy', 'version']);
        });
        Schema::create('vendor_registration_media', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->unsignedBigInteger('store_id');
            $t->string('directory', 100);
            $t->string('name', 191);
            $t->char('sha256', 64);
            $t->string('state', 20)->default('prepared');
            $t->timestamps();
            $t->unique(['store_id', 'directory', 'name']);
        });
        Schema::create('vendor_registration_consents', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->unsignedBigInteger('vendor_id');
            $t->unsignedBigInteger('store_id');
            $t->unsignedBigInteger('legal_policy_version_id');
            $t->string('locale', 20);
            $t->string('evidence_kind', 40);
            $t->char('presentation_hash', 64);
            $t->dateTime('accepted_at');
            $t->string('source', 20);
            $t->dateTime('created_at');
            $t->foreign('vendor_id')->references('id')->on('vendors')->restrictOnDelete();
            $t->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
            $t->foreign('legal_policy_version_id')->references('id')->on('legal_policy_versions')->restrictOnDelete();
            $t->unique(['store_id', 'legal_policy_version_id'], 'registration_consent_store_policy_unique');
        });
        $this->protectEvidence();
    }

    private function protectEvidence(): void
    {
        $driver = DB::connection()->getDriverName();
        foreach (['legal_policy_versions', 'vendor_registration_consents'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $action) {
                $name = $table.'_immutable_'.strtolower($action);
                if ($driver === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER $name BEFORE $action ON $table BEGIN SELECT RAISE(ABORT, 'Immutable registration policy evidence'); END");
                } elseif ($driver === 'mysql') {
                    DB::unprepared("CREATE TRIGGER $name BEFORE $action ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable registration policy evidence'");
                } else {
                    throw new LogicException('Policy evidence immutability requires a reviewed database driver.');
                }
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Legal evidence requires an approved preservation plan; destructive rollback is disabled.');
    }
};
