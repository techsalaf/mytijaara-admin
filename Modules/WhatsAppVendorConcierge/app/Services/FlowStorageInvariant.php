<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Illuminate\Support\Facades\DB;

final class FlowStorageInvariant
{
    public const TABLES = ['vendors', 'stores', 'business_settings', 'translations', 'storages', 'store_schedule', 'vendor_security_tokens', 'legal_policy_versions', 'vendor_registration_media', 'vendor_registration_consents', 'wa_vendor_flow_sessions', 'wa_vendor_flow_receipts', 'wa_vendor_flow_media', 'wa_vendor_flow_events', 'wa_vendor_flow_sync', 'onboarding_sessions', 'onboarding_events', 'whatsapp_contacts', 'whatsapp_conversations', 'whatsapp_messages', 'whatsapp_inbound_receipts', 'whatsapp_credential_tokens', 'whatsapp_notification_deliveries'];

    public function assertSafe(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        $rows = DB::table('information_schema.TABLES')->whereRaw('TABLE_SCHEMA=DATABASE()')->whereIn('TABLE_NAME', self::TABLES)->get(['TABLE_NAME', 'ENGINE']);
        if ($rows->count() !== count(self::TABLES) || $rows->contains(fn ($r) => strtoupper($r->ENGINE ?? '') !== 'INNODB')) {
            throw new \RuntimeException('Flow registration requires transactional storage for every participating table.');
        }
        $keys = DB::select("SELECT k.COLUMN_NAME AS column_name,k.REFERENCED_TABLE_NAME AS parent_table,r.DELETE_RULE AS delete_rule FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='vendor_registration_consents' AND k.REFERENCED_TABLE_NAME IS NOT NULL");
        $expected = ['vendor_id' => 'vendors', 'store_id' => 'stores', 'legal_policy_version_id' => 'legal_policy_versions'];
        foreach ($keys as $key) {
            if (($expected[$key->column_name] ?? null) === $key->parent_table && $key->delete_rule === 'RESTRICT') {
                unset($expected[$key->column_name]);
            }
        }
        if ($expected) {
            throw new \RuntimeException('Flow registration requires restrictive consent evidence foreign keys.');
        }
    }
}
