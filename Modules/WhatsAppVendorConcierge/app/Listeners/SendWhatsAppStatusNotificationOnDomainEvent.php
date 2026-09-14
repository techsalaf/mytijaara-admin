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
