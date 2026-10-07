<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        foreach (['wa_vendor_flow_sessions', 'wa_vendor_flow_receipts', 'wa_vendor_flow_media', 'wa_vendor_flow_events', 'wa_vendor_flow_sync', 'onboarding_sessions', 'onboarding_events', 'whatsapp_contacts', 'whatsapp_conversations', 'whatsapp_messages', 'whatsapp_inbound_receipts', 'whatsapp_credential_tokens', 'whatsapp_notification_deliveries'] as $table) {
            $engine = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            if (! $engine) {
                throw new LogicException('Flow schema is incomplete; inspect migrations before activation.');
            }
            if (strtoupper($engine->engine) !== 'INNODB') {
                DB::statement("ALTER TABLE `$table` ENGINE=InnoDB");
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve transactional Flow records; destructive rollback is disabled.');
    }
};
