<?php

namespace Modules\WhatsAppVendorConcierge\app\Jobs;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\WhatsAppVendorConcierge\app\Models\NotificationDelivery;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingEvent;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;

class SendVendorStatusNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TYPE_APPROVED = 'approved';
    public const TYPE_DENIED = 'denied';
    public const TYPE_SUSPENDED = 'suspended';
    public const TYPE_UNSUSPENDED = 'unsuspended';

    public const BODY_PARAMETER_COUNTS = ['approved' => 1, 'denied' => 2, 'suspended' => 3, 'unsuspended' => 3];

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public int $storeId,
        public int|string $status,
        public ?string $rejectionNote = null,
        public ?string $version = null
    ) {
        $this->onConnection(config('whatsapp-vendor-concierge.queue.connection', 'database'));
        $this->onQueue(config('whatsapp-vendor-concierge.queue.jobs.send_message', 'whatsapp.send_message'));
    }

    public function handle(WhatsAppGateway $gateway): void
    {
        try {
            $store = Store::with('vendor')->find($this->storeId);
            if (!$store || !$store->vendor) {
                Log::warning('SendVendorStatusNotification: Store or vendor not found', ['store_id' => $this->storeId]);
                return;
            }

            $vendor = $store->vendor;
            $type = match ($this->status) {
                1, '1', 'approved' => self::TYPE_APPROVED,
                0, '0', 'denied' => self::TYPE_DENIED,
                default => (string) $this->status,
            };
            $expectedStatus = match ($type) { 'approved' => 1, 'denied' => 0, default => null };
            if (($expectedStatus !== null && ($vendor->status === null || (int) $vendor->status !== $expectedStatus))
                || ($type === 'suspended' && (int) $store->status !== 0)
                || ($type === 'unsuspended' && (int) $store->status !== 1)) {
                Log::info('Stale WhatsApp status notification discarded', ['store_id' => $store->id, 'type' => $type]);
                if ($this->version) NotificationDelivery::where('store_id', $store->id)->where('state_version', $this->version)
                    ->where('status', '!=', 'sent')->update(['status' => 'cancelled', 'error_code' => 'superseded_decision']);
                return;
            }
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

            app(\Modules\WhatsAppVendorConcierge\app\Services\VendorConversationState::class)->synchronize($store, $type, $contact);
            $prefService = app(\Modules\WhatsAppVendorConcierge\app\Services\NotificationPreferenceService::class);
            $isCritical = in_array((string) $this->status, ['suspended', 'payment_failed']);
            $eligibility = $prefService->canReceiveNotification($contact, 'status_alerts', $isCritical);
            if (!$eligibility['allowed']) {
                Log::info('SendVendorStatusNotification: Suppressed by vendor preference', [
                    'contact_id' => $contact->id,
                    'vendor_id' => $vendor->id,
                    'reason' => $eligibility['reason'],
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

            $type = match ($this->status) {
                1, '1', 'approved' => self::TYPE_APPROVED,
                0, '0', 'denied' => self::TYPE_DENIED,
                default => (string) $this->status,
            };

            $version = $this->version ?? hash('sha256', implode('|', [$vendor->id, $type, $vendor->updated_at?->getTimestamp(), (string) $this->rejectionNote]));
            $idempotencyKey = hash('sha256', "{$store->id}:{$type}:{$version}");

            // Atomic concurrency-safe lease claiming
            $delivery = null;
            $claimAcquired = false;

            DB::transaction(function () use ($store, $type, $version, $idempotencyKey, &$delivery, &$claimAcquired) {
                $delivery = NotificationDelivery::firstOrCreate(
                    [
                        'store_id' => $store->id,
                        'notification_type' => $type,
                        'state_version' => $version,
                    ],
                    [
                        'status' => 'pending',
                        'attempt_count' => 0,
                        'idempotency_key' => $idempotencyKey,
                    ]
                );

                if ($delivery->status === 'sent') {
                    $claimAcquired = false;
                    return;
                }

                if ($delivery->hasExceededAttempts(5)) {
                    Log::warning('NotificationDelivery exceeded max attempts', ['delivery_id' => $delivery->id]);
                    $claimAcquired = false;
                    return;
                }

                // Acquire claim if pending/failed OR if processing lease has expired
                $rows = NotificationDelivery::where('id', $delivery->id)
                    ->where(function ($q) {
                        $q->whereIn('status', ['pending', 'failed'])
                          ->orWhere(function ($sub) {
                              $sub->where('status', 'processing')
                                  ->where(function ($exp) {
                                      $exp->whereNull('claim_expires_at')
                                          ->orWhere('claim_expires_at', '<', now());
                                  });
                          });
                    })
                    ->update([
                        'status' => 'processing',
                        'claimed_at' => now(),
                        'claim_expires_at' => now()->addMinutes(5),
                        'attempt_count' => DB::raw('attempt_count + 1'),
                        'idempotency_key' => $idempotencyKey,
                    ]);

                $claimAcquired = ($rows > 0);
            });

            if (!$claimAcquired) {
                Log::info('Notification delivery skipped: already claimed, sent, or max attempts reached', [
                    'store_id' => $store->id,
                    'type' => $type,
                    'version' => $version,
                ]);
                return;
            }

            $lastInbound = WhatsAppMessage::whereHas('conversation', fn ($q) => $q->where('contact_id', $contact->id))
                ->where('direction', 'inbound')->latest('created_at')->value('created_at');
            $insideWindow = $lastInbound && $lastInbound->greaterThanOrEqualTo(now()->subHours(24));
            $channel = $insideWindow ? 'text' : 'template';

            $templateName = config("whatsapp-vendor-concierge.messaging.templates.{$type}");
            $locale = config("whatsapp-vendor-concierge.messaging.template_locales.{$type}");

            if (!$insideWindow && empty($templateName)) {
                $delivery->update([
                    'status' => 'failed',
                    'channel' => 'template',
                    'error_code' => 'missing_template_config',
                ]);
                throw new \RuntimeException("Approved WhatsApp template for '{$type}' is not configured.");
            }

            $components = $this->buildTemplateComponents($type, $store, $vendor, $loginUrl);

            $result = $insideWindow
                ? ($type === self::TYPE_DENIED
                    ? $gateway->sendButtonMessage($contact->phone_number, $message, [['id' => 'talk_support', 'title' => 'Talk to Support']])
                    : $gateway->sendTextMessage($contact->phone_number, $message))
                : $gateway->sendTemplateMessage($contact->phone_number, $templateName, $components, $locale);

            if (isset($result['error'])) {
                $delivery->update([
                    'status' => 'failed',
                    'channel' => $channel,
                        'error_code' => (string) ($result['error']['code'] ?? 'meta_error'),
                    'metadata' => [
                        'channel' => $channel,
                        'template' => $insideWindow ? null : $templateName,
                        'locale' => $locale,
                        'error' => $result['error'],
                    ],
                ]);
                throw new \RuntimeException('WhatsApp status notification was rejected by Meta.');
            }

            $delivery->update([
                'status' => 'sent',
                'sent_at' => now(),
                'channel' => $channel,
                'provider_message_id' => $result['messages'][0]['id'] ?? null,
                'metadata' => [
                    'channel' => $channel,
                    'template' => $insideWindow ? null : $templateName,
                    'locale' => $locale,
                ],
            ]);

            Log::info('WhatsApp vendor status notification delivered', [
                'store_id' => $store->id,
                'vendor_id' => $vendor->id,
                'status' => $this->status,
                'channel' => $channel,
            ]);
        } catch (\Throwable $e) {
            Log::error('SendVendorStatusNotification failed', [
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'store_id' => $this->storeId,
            ]);
            throw $e;
        }
    }

    /**
     * Build template parameters/components for Meta template API.
     */
    protected function buildTemplateComponents(string $type, Store $store, $vendor, string $loginUrl): array
    {
        $supportUrl = url('/vendor/auth/login');
        return match ($type) {
            self::TYPE_APPROVED => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $store->name],
                    ],
                ],
            ],
            self::TYPE_DENIED => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $vendor->f_name ?? 'Partner'],
                        ['type' => 'text', 'text' => $this->rejectionNote ?: 'Please check your application details.'],
                    ],
                ],
            ],
            self::TYPE_SUSPENDED => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $vendor->f_name ?? 'Partner'],
                        ['type' => 'text', 'text' => $store->name],
                        ['type' => 'text', 'text' => $supportUrl],
                    ],
                ],
            ],
            self::TYPE_UNSUSPENDED => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $vendor->f_name ?? 'Partner'],
                        ['type' => 'text', 'text' => $store->name],
                        ['type' => 'text', 'text' => $loginUrl],
                    ],
                ],
            ],
            default => [],
        };
    }
}
