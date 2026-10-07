<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_vendor_flow_sessions', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->unsignedBigInteger('onboarding_session_id')->unique();
            $t->unsignedBigInteger('contact_id');
            $t->char('token_hash', 64)->unique();
            $t->string('sender', 20);
            $t->string('flow_id', 100);
            $t->string('definition_version', 80);
            $t->string('locale', 20);
            $t->string('state', 40);
            $t->string('screen', 40)->default('OWNER');
            $t->longText('draft')->nullable();
            $t->text('policy_manifest');
            $t->dateTime('expires_at');
            $t->dateTime('consumed_at')->nullable();
            $t->unsignedBigInteger('vendor_id')->nullable();
            $t->unsignedBigInteger('store_id')->nullable();
            $t->string('error_code', 60)->nullable();
            $t->string('notification_status', 30)->default('pending');
            $t->timestamps();
            $t->index(['state', 'expires_at']);
        });
        Schema::create('wa_vendor_flow_receipts', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->string('message_id', 191)->unique();
            $t->unsignedBigInteger('flow_session_id');
            $t->string('state', 30);
            $t->timestamps();
        });
        Schema::create('wa_vendor_flow_media', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->unsignedBigInteger('flow_session_id');
            $t->string('role', 20);
            $t->char('media_identity', 64)->unique();
            $t->string('path', 512)->unique();
            $t->string('mime', 80);
            $t->char('sha256', 64);
            $t->unsignedInteger('bytes');
            $t->string('state', 20)->default('staged');
            $t->timestamps();
            $t->index(['flow_session_id', 'role']);
        });
        Schema::create('wa_vendor_flow_events', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->unsignedBigInteger('flow_session_id')->nullable();
            $t->string('event', 60);
            $t->string('screen', 40)->nullable();
            $t->dateTime('created_at');
            $t->index(['event', 'created_at']);
        });
        Schema::create('wa_vendor_flow_sync', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->string('definition_version', 80)->primary();
            $t->char('asset_hash', 64)->nullable();
            $t->string('draft_flow_id', 100)->nullable();
            $t->string('published_flow_id', 100)->nullable();
            $t->string('status', 30);
            $t->string('error_code', 100)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve session and operational evidence before an approved rollback.');
    }
};
