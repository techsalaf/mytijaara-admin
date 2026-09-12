<?php

namespace Modules\WhatsAppVendorConcierge\app\Jobs;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingEvent;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;

class SendVendorStatusNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TYPE_APPROVED = 'approved';
    public const TYPE_DENIED = 'denied';
    public const TYPE_SUSPENDED = 'suspended';
    public const TYPE_UNSUSPENDED = 'unsuspended';

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public int $storeId,
        public int|string $status,
        public ?string $rejectionNote = null
    ) {}

    public function handle(WhatsAppGateway $gateway): void
    {
        try {
            $store = Store::with('vendor')->find($this->storeId);
            if (!$store || !$store->vendor) {
                Log::warning('SendVendorStatusNotification: Store or vendor not found', ['store_id' => $this->storeId]);
                return;
            }

            $vendor = $store->vendor;
            $contact = WhatsAppContact::where('vendor_id', $vendor->id)->first();

            if (!$contact && !empty($vendor->phone)) {
                $rawPhone = preg_replace('/[^0-9]/', '', (string) $vendor->phone);
                $variations = [
                    $rawPhone,
                    ltrim($rawPhone, '0'),
                    '234' . ltrim($rawPhone, '0'),
                ];
                $contact = WhatsAppContact::whereIn('phone_number', $variations)->first();
            }

            if (!$contact) {
                Log::info('SendVendorStatusNotification: No WhatsApp contact linked for vendor', [
                    'vendor_id' => $vendor->id,
                    'phone' => $vendor->phone,
                ]);
                return;
            }

            $loginUrl = url('/vendor/auth/login');
            $message = match ($this->status) {
                1, '1', 'approved' => "🎉 Great news, {$vendor->f_name}!\n\n" .
                    "Your store *{$store->name}* has been approved by the MyTijaara team! 🚀\n\n" .
                    "You can now log in to your vendor dashboard to manage your products and orders:\n" .
                    "🔗 {$loginUrl}\n\n" .
                    "We're thrilled to have you onboard! If you need any assistance, reply *Help* or *Support* anytime.",

                0, '0', 'denied' => "Hello {$vendor->f_name},\n\n" .
                    "Your vendor application for *{$store->name}* was reviewed by our team and could not be approved at this time.\n\n" .
                    (!empty($this->rejectionNote) ? "📝 *Reason:* {$this->rejectionNote}\n\n" : "") .
                    "If you would like to correct your details or have any questions, please reply *Support* to connect with an agent.",

                'suspended' => "⚠️ Notice: Your store *{$store->name}* has been temporarily suspended by administration.\n\n" .
                    "If you believe this is in error or need assistance, reply *Support* to talk to an agent.",

                'unsuspended' => "✅ Notice: Your store *{$store->name}* has been re-activated by administration.\n\n" .
                    "You can log in and continue managing your store as normal:\n🔗 {$loginUrl}",

                default => null,
            };

            if (!$message) {
                return;
            }

            $gateway->sendTextMessage($contact->phone_number, $message);

            // Log event if session exists
            $conversation = WhatsAppConversation::where('contact_id', $contact->id)->latest()->first();
            if ($conversation) {
                if ($this->status === 1 || $this->status === '1' || $this->status === 'approved' || $this->status === self::TYPE_APPROVED) {
                    $conversation->update([
                        'state' => 'ai_active',
                        'vendor_id' => $vendor->id,
                    ]);
                    $contact->update([
                        'contact_type' => 'vendor',
                        'vendor_id' => $vendor->id,
                    ]);
                }

                if (!empty($conversation->onboarding_session_id)) {
                    OnboardingEvent::log(
                        $conversation->onboarding_session_id,
                        $contact->id,
                        'status_notification_sent',
                        (string) $this->status,
                        ['store_id' => $store->id, 'vendor_id' => $vendor->id, 'note' => $this->rejectionNote]
                    );
                }
            }

            Log::info('WhatsApp vendor status notification delivered', [
                'store_id' => $store->id,
                'vendor_id' => $vendor->id,
                'status' => $this->status,
            ]);
        } catch (\Throwable $e) {
            Log::error('SendVendorStatusNotification failed', [
                'error' => $e->getMessage(),
                'store_id' => $this->storeId,
            ]);
            throw $e;
        }
    }
}
