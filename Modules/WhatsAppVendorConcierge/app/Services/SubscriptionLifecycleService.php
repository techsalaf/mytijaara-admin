<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\CentralLogics\Helpers;
use App\Models\BusinessSetting;
use App\Models\Store;
use App\Models\SubscriptionPackage;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Modules\WhatsAppVendorConcierge\app\Models\NotificationDelivery;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingEvent;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;

class SubscriptionLifecycleService
{
    public const STATE_REQUIRED = 'payment_required';
    public const STATE_LINK_ISSUED = 'payment_link_issued';
    public const STATE_PENDING = 'payment_pending';
    public const STATE_SUCCEEDED = 'payment_succeeded';
    public const STATE_FAILED = 'payment_failed';
    public const STATE_CANCELLED = 'payment_cancelled';
    public const STATE_EXPIRED = 'payment_expired';

    public function __construct(
        protected WhatsAppGateway $gateway
    ) {}

    /**
     * Get current payment state for the session.
     */
    public function getPaymentState(OnboardingSession $session): string
    {
        $data = $session->collected_data ?? [];
        return $data['payment_state'] ?? self::STATE_REQUIRED;
    }

    /**
     * Generate or regenerate signed expiring payment link.
     */
    public function issuePaymentLink(OnboardingSession $session, int $validDays = 7): string
    {
        $expiresAt = now()->addDays($validDays);
        $url = URL::temporarySignedRoute(
            'whatsapp.onboarding.subscription-payment',
            $expiresAt,
            ['session' => $session->id]
        );

        $data = $session->collected_data ?? [];
        $data['payment_state'] = self::STATE_LINK_ISSUED;
        $data['payment_link'] = $url;
        $data['payment_link_expires_at'] = $expiresAt->toIso8601String();

        $session->update([
            'collected_data' => $data,
        ]);

        OnboardingEvent::log(
            $session->id,
            $session->contact_id,
            'payment_link_issued',
            'subscription',
            ['expires_at' => $expiresAt->toIso8601String()]
        );

        return $url;
    }

    /**
     * Mark payment as pending when vendor opens the link.
     */
    public function recordPending(OnboardingSession $session): void
    {
        $data = $session->collected_data ?? [];
        if (($data['payment_state'] ?? null) !== self::STATE_SUCCEEDED) {
            $data['payment_state'] = self::STATE_PENDING;
            $data['payment_pending_at'] = now()->toIso8601String();
            $session->update(['collected_data' => $data]);
        }
    }

    /**
     * Reconcile payment against canonical core database.
     */
    public function reconcilePayment(OnboardingSession $session): array
    {
        $store = Store::find($session->store_id);
        if (!$store) {
            return ['status' => 'not_found', 'state' => $this->getPaymentState($session)];
        }

        $data = $session->collected_data ?? [];

        // Check if store subscription is already active in core
        $activeSub = $store->store_sub_update_application;
        if ($activeSub && (int) $activeSub->status === 1) {
            if (($data['payment_state'] ?? null) !== self::STATE_SUCCEEDED) {
                $data['payment_state'] = self::STATE_SUCCEEDED;
                $data['payment_succeeded_at'] = now()->toIso8601String();
                $session->update(['collected_data' => $data]);
                $this->sendContinuationNotification($session, self::STATE_SUCCEEDED);
            }
            return ['status' => 'success', 'state' => self::STATE_SUCCEEDED, 'active' => true];
        }

        // Check if link expired
        $expiresAtStr = $data['payment_link_expires_at'] ?? null;
        if ($expiresAtStr && Carbon::parse($expiresAtStr)->isPast()) {
            if ($data['payment_state'] !== self::STATE_SUCCEEDED) {
                $data['payment_state'] = self::STATE_EXPIRED;
                $session->update(['collected_data' => $data]);
            }
            return ['status' => 'expired', 'state' => self::STATE_EXPIRED];
        }

        return ['status' => 'pending', 'state' => $data['payment_state'] ?? self::STATE_REQUIRED];
    }

    /**
     * Verified canonical callback / webhook handler.
     * Activates subscription through core Helpers::subscription_plan_chosen idempotently.
     */
    public function handlePaymentSuccess(int $storeId, string $method, ?string $reference = null): array
    {
        $store = Store::findOrFail($storeId);
        $session = OnboardingSession::where('store_id', $store->id)->latest()->first();

        // Prevent double activation
        $alreadyActive = $store->store_sub_update_application && (int) $store->store_sub_update_application->status === 1;

        if (!$alreadyActive) {
            $packageId = $store->package_id;
            if (!$packageId && $session) {
                $packageId = $session->collected_data['package_id'] ?? null;
            }

            if (!$packageId) {
                throw new \InvalidArgumentException("No subscription package found for store {$store->id}");
            }

            // Call canonical core activation
            Helpers::subscription_plan_chosen(
                store_id: $store->id,
                package_id: $packageId,
                payment_method: $method,
                discount: 0,
                pending_bill: 0,
                reference: $reference,
                type: 'new_join'
            );

            $store->refresh();
        }

        if ($session) {
            $data = $session->collected_data ?? [];
            if (($data['payment_state'] ?? null) !== self::STATE_SUCCEEDED) {
                $data['payment_state'] = self::STATE_SUCCEEDED;
                $data['payment_reference'] = $reference;
                $data['payment_method'] = $method;
                $data['payment_confirmed_at'] = now()->toIso8601String();
                $session->update(['collected_data' => $data]);

                OnboardingEvent::log(
                    $session->id,
                    $session->contact_id,
                    'payment_succeeded',
                    'subscription',
                    ['method' => $method, 'reference' => $reference]
                );

                // Send WhatsApp continuation
                $this->sendContinuationNotification($session, self::STATE_SUCCEEDED);
            }
        }

        return [
            'success' => true,
            'store_id' => $store->id,
            'state' => self::STATE_SUCCEEDED,
        ];
    }

    /**
     * Handle payment failure or cancellation safely.
     */
    public function handlePaymentFailure(int $storeId, string $status = 'failed', ?string $reason = null): void
    {
        $session = OnboardingSession::where('store_id', $storeId)->latest()->first();
        if (!$session) {
            return;
        }

        $state = ($status === 'cancelled') ? self::STATE_CANCELLED : self::STATE_FAILED;
        $data = $session->collected_data ?? [];
        $data['payment_state'] = $state;
        $data['payment_failure_reason'] = $reason;
        $session->update(['collected_data' => $data]);

        OnboardingEvent::log(
            $session->id,
            $session->contact_id,
            'payment_failed',
            'subscription',
            ['status' => $status, 'reason' => $reason]
        );

        $this->sendContinuationNotification($session, $state);
    }

    /**
     * Send automatic WhatsApp continuation notification respecting the 24-hour Meta window.
     */
    public function sendContinuationNotification(OnboardingSession $session, string $state): void
    {
        $contact = $session->contact;
        if (!$contact) {
            return;
        }

        $store = Store::find($session->store_id);
        $storeName = $store?->name ?? ($session->collected_data['business_name'] ?? 'Your Store');

        $lastInbound = WhatsAppMessage::whereHas('conversation', fn ($q) => $q->where('contact_id', $contact->id))
            ->where('direction', 'inbound')->latest('created_at')->value('created_at');
        $insideWindow = $lastInbound && $lastInbound->greaterThanOrEqualTo(now()->subHours(24));

        if ($state === self::STATE_SUCCEEDED) {
            $msg = "✅ *Payment Confirmed!*\n\n" .
                "Your subscription payment for *{$storeName}* has been successfully processed.\n\n" .
                "Your store application is now under final admin review ⏳. " .
                "We will notify you here once approved!";

            if ($insideWindow) {
                $this->gateway->sendTextMessage($contact->phone_number, $msg);
            } else {
                $template = config('whatsapp-vendor-concierge.messaging.templates.subscription_confirmed', 'subscription_payment_confirmed');
                $this->gateway->sendTemplateMessage(
                    $contact->phone_number,
                    $template,
                    [['type' => 'text', 'text' => $storeName]],
                    $contact->locale ?? 'en'
                );
            }
        } elseif ($state === self::STATE_FAILED || $state === self::STATE_CANCELLED) {
            $retryUrl = $this->issuePaymentLink($session);
            $msg = "⚠️ *Payment {$state}*\n\n" .
                "Your subscription payment for *{$storeName}* could not be completed.\n\n" .
                "Tap below to retry your payment securely:";

            if ($insideWindow) {
                $this->gateway->sendCtaUrlMessage(
                    $contact->phone_number,
                    $msg,
                    'Retry Payment',
                    $retryUrl,
                    'MyTijaara Payment'
                );
            }
        }
    }
}
