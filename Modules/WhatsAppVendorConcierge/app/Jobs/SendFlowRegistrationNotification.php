<?php

namespace Modules\WhatsAppVendorConcierge\app\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SendFlowRegistrationNotification implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 3;

    public array $backoff = [15, 60, 120];

    public function __construct(public int $sessionId) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $s = \Modules\WhatsAppVendorConcierge\app\Models\VendorFlowSession::lockForUpdate()->findOrFail($this->sessionId);
            if (! $s->consumed_at || $s->notification_status === 'sent') {
                return;
            }
            $origin = rtrim((string) config('app.url'), '/');
            $parts = parse_url($origin);
            if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new \RuntimeException('A trusted HTTPS credential origin is required.');
            }
            $vendor = \App\Models\Vendor::findOrFail($s->vendor_id);
            $store = \App\Models\Store::findOrFail($s->store_id);
            $token = app(\App\Services\VendorSecurityTokenService::class)->issue($vendor, \App\Services\VendorSecurityTokenService::FLOW_SETUP, $store);
            $url = $origin.route('whatsapp.flow.password', [], false).'#setup='.$token;
            app(\Modules\WhatsAppVendorConcierge\app\Services\FlowMetaClient::class)->text($s->sender, 'Your application was received and is awaiting approval. Set your password using this private link (15 minutes): '.$url."\nSetting a password does not approve your application. Reply SETUP for a fresh link.");
            $s->update(['notification_status' => 'sent']);
            DB::afterCommit(function () use ($vendor, $store) {
                try {
                    app(\App\Services\VendorRegistrationNotifier::class)->send($vendor, \App\Models\Module::findOrFail($store->module_id));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Flow registration notification preparation failed', ['vendor_id' => $vendor->id, 'exception' => $e::class]);
                }
            });
        }, 3);
    }
}
