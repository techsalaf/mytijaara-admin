<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\Models\Store;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;

class VendorConversationState
{
    public function synchronize(Store $store, string $decision, ?WhatsAppContact $contact = null): void
    {
        if (!in_array($decision, ['approved', 'denied'], true)) return;
        $contact ??= WhatsAppContact::where('vendor_id', $store->vendor_id)->first();
        if (!$contact) return;
        $approved = $decision === 'approved';
        $contact->update(['vendor_id' => $store->vendor_id, 'contact_type' => $approved ? 'vendor' : 'rejected_applicant']);
        $conversation = WhatsAppConversation::where('contact_id', $contact->id)->latest()->first();
        if ($conversation) {
            $conversation->update([
                'vendor_id' => $store->vendor_id, 'current_step' => null,
                'state' => $conversation->state === 'human_handoff' ? 'human_handoff' : ($approved ? 'ai_active' : 'welcome'),
            ]);
        }
        $session = $conversation?->onboarding_session_id
            ? OnboardingSession::find($conversation->onboarding_session_id)
            : OnboardingSession::where('vendor_id', $store->vendor_id)->latest()->first();
        $session?->update(['status' => $approved ? 'approved' : 'rejected']);
    }
}
