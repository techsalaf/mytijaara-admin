<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\FlowControl;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class Confirmation
{
    private function facts(string $hash, string $nonce): array
    {
        try {
            $publicKeyHash = hash('sha256', preg_replace('/\s+/', '', app(EndpointProbe::class)->key()->getPublicKey()->toString('PKCS8')));
        } catch (\Throwable) {
            $publicKeyHash = null;
        }

        return ['endpoint' => config('whatsapp-vendor-flow.endpoint_url'), 'graph_version' => config('whatsapp-vendor-flow.graph_version'), 'public_key_hash' => $publicKeyHash, 'admin' => auth('admin')->id(), 'nonce' => $nonce, 'asset_hash' => $hash, 'definition_version' => config('whatsapp-vendor-flow.definition_version'), 'flow' => DB::table('wa_vendor_flow_sync')->where('definition_version', config('whatsapp-vendor-flow.definition_version'))->value('draft_flow_id'), 'waba' => config('whatsapp-vendor-concierge.api.business_account_id'), 'phone' => config('whatsapp-vendor-concierge.api.phone_number_id'), 'environment' => app()->environment()];
    }

    public function issue(string $hash, string $nonce): string
    {
        return Crypt::encryptString(json_encode(['facts' => $this->facts($hash, $nonce), 'expires' => now()->addMinutes(5)->timestamp], JSON_THROW_ON_ERROR));
    }

    public function verify(string $proof, string $hash, string $nonce): void
    {
        try {
            $value = json_decode(Crypt::decryptString($proof), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(409, 'Confirmation is invalid. Review again.');
        }
        abort_unless(($value['expires'] ?? 0) > now()->timestamp && ($value['facts'] ?? null) === $this->facts($hash, $nonce), 409, 'Confirmation expired or the target changed. Review again.');
    }
}
