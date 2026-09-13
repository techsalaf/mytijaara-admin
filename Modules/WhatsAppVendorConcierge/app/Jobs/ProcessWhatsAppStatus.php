<?php

namespace Modules\WhatsAppVendorConcierge\app\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;

class ProcessWhatsAppStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;
    public int $backoff = 10;

    public function __construct(public array $statusData)
    {
        // Retain diagnostic codes only; Meta error text may contain private data.
        $this->statusData = array_intersect_key($statusData, array_flip(['id', 'status', 'timestamp']));
        $this->statusData['error_codes'] = array_values(array_filter(array_map(
            fn ($error) => is_array($error) && isset($error['code']) && is_numeric($error['code']) ? (int) $error['code'] : null,
            is_array($statusData['errors'] ?? null) ? $statusData['errors'] : []
        ), fn ($code) => $code !== null));
        $this->onConnection(config('whatsapp-vendor-concierge.queue.connection', 'database'));
        $this->onQueue(config('whatsapp-vendor-concierge.queue.jobs.process_incoming'));
    }

    public function handle(): void
    {
        if (!is_string($this->statusData['id'] ?? null)
            || !in_array($this->statusData['status'] ?? null, ['sent', 'delivered', 'read', 'failed'], true)
            || !ctype_digit((string) ($this->statusData['timestamp'] ?? ''))) {
            return;
        }

        $found = DB::transaction(function () {
            $message = WhatsAppMessage::where('whatsapp_message_id', $this->statusData['id'])
                ->where('direction', 'outbound')->lockForUpdate()->first();
            if (!$message) {
                return false;
            }
            $message->applyReceipt($this->statusData);
            return true;
        });

        // Receipts can arrive before the sender persists its result. Bounded retry
        // handles that race; unknown IDs never create contacts or inbound messages.
        if (!$found && $this->job && $this->attempts() < $this->tries) {
            $this->release($this->backoff);
        } elseif (!$found) {
            Log::notice('WhatsApp receipt has no outbound message', ['message_id' => $this->statusData['id']]);
        }
    }
}
