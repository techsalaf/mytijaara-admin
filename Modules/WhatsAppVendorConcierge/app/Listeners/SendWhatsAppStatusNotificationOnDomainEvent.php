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
        $queue = config('whatsapp-vendor-concierge.queue.jobs.send_whatsapp_message', 'notifications');

        SendVendorStatusNotification::dispatch(
            $event->store->id,
            $event->status,
            $event->rejectionNote,
            $event->version
        )->onQueue($queue)->afterCommit();
    }
}
