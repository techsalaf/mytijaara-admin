<?php

namespace Modules\WhatsAppVendorConcierge\app\Listeners;

use App\Events\VendorApplicationStatusChanged;
use Modules\WhatsAppVendorConcierge\app\Jobs\SendVendorStatusNotification;

class SendWhatsAppStatusNotificationOnDomainEvent
{
    /**
     * Handle canonical domain event for vendor application status change.
     * Sole authoritative entry point for status notification job dispatch.
     */
    public function handle(VendorApplicationStatusChanged $event): void
    {
        try {
            $this->dispatchNotification($event);
        } catch (\Throwable $exception) {
            // An unavailable optional module table/queue must not undo a host
            // decision. Persisted status can be reconciled by module maintenance.
            \Illuminate\Support\Facades\Log::error('Optional vendor notification dispatch failed', [
                'store_id' => $event->store->id,
                'status' => $event->status,
                'exception' => $exception::class,
            ]);
        }
    }

    private function dispatchNotification(VendorApplicationStatusChanged $event): void
    {
        app(\Modules\WhatsAppVendorConcierge\app\Services\VendorConversationState::class)->synchronize($event->store, $event->status);
        $queue = config('whatsapp-vendor-concierge.queue.jobs.send_message', 'whatsapp.send_message');

        SendVendorStatusNotification::dispatch(
            $event->store->id,
            $event->status,
            $event->rejectionNote,
            $event->version
        )->onConnection(config('whatsapp-vendor-concierge.queue.connection', 'database'))->onQueue($queue)->afterCommit();
    }
}
