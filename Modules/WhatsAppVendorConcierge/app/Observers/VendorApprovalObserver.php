<?php

namespace Modules\WhatsAppVendorConcierge\app\Observers;

use App\Models\Vendor;
use Illuminate\Support\Facades\Log;
use Modules\WhatsAppVendorConcierge\app\Jobs\SendWhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingEvent;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;

class VendorApprovalObserver
{
    /**
     * Handle the Vendor "updated" event.
     */
    public function updated(Vendor $vendor): void
    {
        // Trigger if status changed OR if rejection_note was added/changed on a pending/rejected vendor
        if (!$vendor->wasChanged('status') && !($vendor->wasChanged('rejection_note') && (int) $vendor->status === 0)) {
            return;
        }

        $contact = WhatsAppContact::where('vendor_id', $vendor->id)->first();
        if (!$contact) {
            // Also check by phone number normalization if vendor_id wasn't linked yet
            $cleanedPhone = preg_replace('/[^0-9]/', '', (string) $vendor->phone);
            if ($cleanedPhone) {
                $contact = WhatsAppContact::where('phone_number', $cleanedPhone)
                    ->orWhere('phone_number', 'LIKE', '%' . substr($cleanedPhone, -10))
                    ->first();
                if ($contact && !$contact->vendor_id) {
                    $contact->update(['vendor_id' => $vendor->id]);
                }
            }
        }

        if (!$contact) {
            return;
        }

        $conversation = WhatsAppConversation::getOrCreateActive($contact->id);

        if ((int) $vendor->status === 1) {
            // Vendor approved!
            Log::info("Dispatching WhatsApp approval notification for vendor #{$vendor->id} to {$contact->phone_number}");

            $store = $vendor->store;
            $storeName = $store?->name ?? 'Your Shop';

            $messageText = "🎉 *Congratulations! Your MyTijaara shop has been approved!*\n\n" .
                "Business: *{$storeName}*\n\n" .
                "Your store is now active and ready for business.\n" .
                "You can now manage your store directly right here on WhatsApp! Try saying:\n" .
                "• \"Show my shop details\"\n" .
                "• \"Add a new product\"\n" .
                "• \"Show my orders\"\n\n" .
                "Welcome to the MyTijaara merchant family! 🚀";

            SendWhatsAppMessage::dispatch(
                $contact->phone_number,
                'text',
                ['body' => $messageText],
                $conversation->id
            )->onQueue(config('whatsapp-vendor-concierge.queue.jobs.send_message', 'whatsapp.send_message'));

            // Update conversation and onboarding session states
            $conversation->update([
                'state' => 'ai_active',
                'vendor_id' => $vendor->id,
            ]);

            $session = OnboardingSession::where('vendor_id', $vendor->id)->latest()->first();
            if ($session) {
                $session->update(['status' => 'approved']);
                OnboardingEvent::log($session->id, $contact->id, 'application_approved', 'admin_review', [
                    'vendor_id' => $vendor->id,
                    'approved_at' => now()->toDateTimeString(),
                ]);
            }
        } elseif ((int) $vendor->status === 0 && !empty($vendor->rejection_note)) {
            // Vendor denied!
            Log::info("Dispatching WhatsApp denial notification for vendor #{$vendor->id} to {$contact->phone_number}");

            $rejectionReason = $vendor->rejection_note;
            $messageText = "⚠️ *MyTijaara Application Update*\n\n" .
                "Hello {$vendor->f_name},\n\n" .
                "Thank you for your interest in selling on MyTijaara. We have reviewed your application, but unfortunately we could not approve it at this time.\n\n" .
                "*Reason:* {$rejectionReason}\n\n" .
                "If you would like to correct this or speak to our team, please reply directly to this message or say *\"Talk to support\"*.";

            SendWhatsAppMessage::dispatch(
                $contact->phone_number,
                'text',
                ['body' => $messageText],
                $conversation->id
            )->onQueue(config('whatsapp-vendor-concierge.queue.jobs.send_message', 'whatsapp.send_message'));

            $conversation->update([
                'state' => 'closed',
            ]);

            $session = OnboardingSession::where('vendor_id', $vendor->id)->latest()->first();
            if ($session) {
                $session->update(['status' => 'rejected']);
                OnboardingEvent::log($session->id, $contact->id, 'application_rejected', 'admin_review', [
                    'vendor_id' => $vendor->id,
                    'rejection_note' => $rejectionReason,
                    'rejected_at' => now()->toDateTimeString(),
                ]);
            }
        }
    }
}
