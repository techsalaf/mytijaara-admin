<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\Models\Store;
use App\Services\StoreAvailabilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Modules\WhatsAppVendorConcierge\app\Jobs\SendWhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\PendingAction;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;

class PendingActionService
{
    public function prepareAvailability(int $contactId, int $conversationId, int $storeId, bool $active): PendingAction
    {
        $contact = WhatsAppContact::findOrFail($contactId);
        $conversation = WhatsAppConversation::findOrFail($conversationId);
        $this->authorize($contact, $conversation);
        $store = Store::whereKey($storeId)->where('vendor_id', $contact->vendor_id)->firstOrFail();
        $payload = ['store_id' => $store->id, 'active' => $active, 'previous_active' => (bool) $store->active];
        $action = PendingAction::create([
            'contact_id' => $contact->id, 'vendor_id' => $contact->vendor_id, 'conversation_id' => $conversation->id,
            'action_type' => 'store_availability', 'payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'preview' => ($active ? 'Open' : 'Pause').' your store: '.$store->name.'?',
            'action_token' => bin2hex(random_bytes(24)), 'expires_at' => now()->addMinutes(10),
        ]);
        SendWhatsAppMessage::dispatch($contact->phone_number, 'button', [
            'body' => $action->preview,
            'buttons' => [
                ['id' => 'action_confirm_'.$action->action_token, 'title' => 'Confirm'],
                ['id' => 'action_cancel_'.$action->action_token, 'title' => 'Cancel'],
            ],
        ], $conversation->id)->onConnection(config('whatsapp-vendor-concierge.queue.connection'))
            ->onQueue(config('whatsapp-vendor-concierge.queue.jobs.send_message'))->afterCommit();
        return $action;
    }

    public function confirm(string $token, int $contactId, int $conversationId, bool $cancel = false): string
    {
        return DB::transaction(function () use ($token, $contactId, $conversationId, $cancel) {
            $contact = WhatsAppContact::lockForUpdate()->findOrFail($contactId);
            $conversation = WhatsAppConversation::lockForUpdate()->findOrFail($conversationId);
            $this->authorize($contact, $conversation);
            $action = PendingAction::where('action_token', $token)->where('contact_id', $contactId)
                ->where('vendor_id', $contact->vendor_id)->where('conversation_id', $conversationId)->lockForUpdate()->first();
            if (!$action || $action->status !== 'pending') {
                return 'This action is unavailable or already completed.';
            }
            if ($action->expires_at->isPast()) {
                $action->update(['status' => 'expired']);
                return 'This confirmation expired. Please request the action again.';
            }
            if ($cancel) {
                $action->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                return 'Action cancelled.';
            }
            if (!hash_equals($action->payload_hash, hash('sha256', json_encode($action->payload, JSON_THROW_ON_ERROR)))) {
                $action->update(['status' => 'failed', 'result_metadata' => ['code' => 'payload_changed']]);
                return 'This action changed. Please request a new preview.';
            }
            $key = 'whatsapp:mutation:'.$contact->vendor_id;
            if (RateLimiter::tooManyAttempts($key, 10)) {
                return 'Please wait a minute before making more changes.';
            }
            RateLimiter::hit($key, 60);
            if ($action->action_type !== 'store_availability') {
                return 'This action is not available through WhatsApp yet.';
            }
            $store = Store::whereKey($action->payload['store_id'])->where('vendor_id', $contact->vendor_id)->lockForUpdate()->firstOrFail();
            if ((int) $store->status !== 1 || (bool) $store->active !== $action->payload['previous_active']) {
                $action->update(['status' => 'cancelled', 'cancelled_at' => now(), 'result_metadata' => ['code' => 'store_changed']]);
                return 'Your store status changed. Please request a new preview.';
            }
            app(StoreAvailabilityService::class)->set($store, $contact->vendor_id, $action->payload['active']);
            $action->update(['status' => 'executed', 'confirmed_at' => now(), 'executed_at' => now(), 'result_metadata' => ['store_id' => $store->id]]);
            return $action->payload['active'] ? 'Your store is open, subject to its schedule.' : 'Your store is paused.';
        });
    }

    private function authorize(WhatsAppContact $contact, WhatsAppConversation $conversation): void
    {
        abort_unless(!$contact->is_blocked && $contact->isVendor() && (int) $contact->vendor?->status === 1
            && (int) $conversation->contact_id === (int) $contact->id
            && (int) $conversation->vendor_id === (int) $contact->vendor_id
            && $conversation->state === 'ai_active', 403);
    }
}
