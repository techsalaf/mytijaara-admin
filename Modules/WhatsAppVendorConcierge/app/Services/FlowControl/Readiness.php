<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\FlowControl;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\WhatsAppVendorConcierge\app\Services\FlowDefinitionValidator;

class Readiness
{
    public function summary(): array
    {
        $items = [];
        try {
            $keyHash = hash('sha256', preg_replace('/\s+/', '', app(EndpointProbe::class)->key()->getPublicKey()->toString('PKCS8')));
        } catch (\Throwable) {
            $keyHash = null;
        }
        foreach (['health', 'inspect', 'remote', 'key_check', 'key_configure', 'ping', 'compare'] as $action) {
            $op = DB::table('wa_flow_control_operations')->where('action', $action)->latest('created_at')->first();
            $ctx = $op ? json_decode($op->context, true) : [];
            $fresh = $op && $op->state === 'succeeded' && $op->updated_at >= now()->subMinutes(10)->toDateTimeString() && ($ctx['definition_version'] ?? '') === config('whatsapp-vendor-flow.definition_version') && ($ctx['asset_hash'] ?? '') === hash_file('sha256', app(FlowDefinitionValidator::class)->path()) && ($ctx['waba'] ?? '') === (string) config('whatsapp-vendor-concierge.api.business_account_id') && ($ctx['phone_id'] ?? '') === (string) config('whatsapp-vendor-concierge.api.phone_number_id');
            $fresh = $fresh && ($ctx['endpoint'] ?? '') === (string) config('whatsapp-vendor-flow.endpoint_url') && ($ctx['graph_version'] ?? null) === config('whatsapp-vendor-flow.graph_version') && ($ctx['environment'] ?? null) === app()->environment();
            if (in_array($action, ['ping', 'key_check', 'key_configure'], true)) {
                $fresh = $fresh && $keyHash !== null && ($ctx['public_key_hash'] ?? '') === $keyHash;
            }
            $items[$action] = ['fresh' => (bool) $fresh, 'at' => $op?->updated_at, 'result' => $op ? json_decode($op->result ?? '{}', true) : []];
        }
        $remote = $items['inspect']['fresh'] ? $items['inspect'] : $items['remote'];
        $key = $items['key_check']['fresh'] ? $items['key_check'] : $items['key_configure'];
        $flow = (string) config('whatsapp-vendor-flow.flow_id');

        return ['public_key_hash' => $keyHash, 'observations' => $items, 'checks' => [
            'Owned phone connected' => $items['health']['fresh'] && ($items['health']['result']['phone']['status'] ?? '') === 'CONNECTED',
            'Published Flow healthy' => $remote['fresh'] && ($remote['result']['flow_id'] ?? '') === $flow && ($remote['result']['status'] ?? '') === 'PUBLISHED' && ($remote['result']['health_available'] ?? false) === true && ($remote['result']['validation_error_count'] ?? 1) === 0,
            'Encryption signature and key match' => $key['fresh'] && ($key['result']['key_signature_status'] ?? '') === 'VALID' && ($key['result']['matches_configured_private_key'] ?? false) === true,
            'Authenticated encrypted endpoint ping' => $items['ping']['fresh'] && ($items['ping']['result']['endpoint'] ?? '') === 'active',
            'Reviewed and remote definition match' => $items['compare']['fresh'] && ($items['compare']['result']['flow_id'] ?? '') === $flow && ($items['compare']['result']['definitions_match'] ?? false) === true && ($items['compare']['result']['local_asset_hash'] ?? '') === hash_file('sha256', app(FlowDefinitionValidator::class)->path()),
        ]];
    }

    public function assertActivation(string $flow): void
    {
        $before = config('whatsapp-vendor-flow.flow_id');
        config(['whatsapp-vendor-flow.flow_id' => $flow]);
        try {
            if (in_array(false, $this->summary()['checks'], true)) {
                throw ValidationException::withMessages(['activation' => 'Activation requires fresh successful connection, publication, encryption, endpoint and remote-comparison observations (10 minutes).']);
            }
        } finally {
            config(['whatsapp-vendor-flow.flow_id' => $before]);
        }
    }
}
