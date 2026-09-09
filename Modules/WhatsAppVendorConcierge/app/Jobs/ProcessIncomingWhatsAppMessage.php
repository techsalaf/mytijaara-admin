<?php

namespace Modules\WhatsAppVendorConcierge\app\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingEvent;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;
use Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService;
use Modules\WhatsAppVendorConcierge\app\Services\ConversationManager;

class ProcessIncomingWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 120;

    public function __construct(
        public array $messageData,
        public array $metaValue
    ) {}

    /**
     * Handle incoming message processing.
     */
    public function handle(
        WhatsAppGateway $gateway,
        VendorOnboardingService $onboardingService,
        ConversationManager $conversationManager
    ): void {
        try {
            // Check idempotency - already processed?
            $existingMessage = WhatsAppMessage::where('whatsapp_message_id', $this->messageData['id'])
                ->where('direction', 'inbound')
                ->first();

            if ($existingMessage) {
                Log::info('Duplicate WhatsApp message ignored', [
                    'message_id' => $this->messageData['id'],
                ]);
                return;
            }

            // Get or create contact
            $contact = $this->getOrCreateContact($this->messageData, $this->metaValue);

            // Get or create conversation
            $conversation = WhatsAppConversation::getOrCreateActive($contact->id);

            // Log the message
            $message = WhatsAppMessage::logInbound($conversation->id, $this->messageData, $this->metaValue);

            // Handle media if present
            if (in_array($this->messageData['type'] ?? '', ['image', 'document', 'video', 'audio'])) {
                $this->handleMedia($message, $this->messageData, $gateway);
            }

            // Process based on conversation state
            $this->processByState($conversation, $contact, $message, $onboardingService, $conversationManager, $gateway);

            // Update conversation activity
            $conversation->update([
                'last_activity_at' => now(),
                'expires_at' => now()->addMinutes(config('whatsapp-vendor-concierge.onboarding.max_inactive_minutes', 30)),
            ]);

        } catch (\Throwable $e) {
            Log::error('ProcessIncomingWhatsAppMessage failed', [
                'message_id' => $this->messageData['id'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle status update webhook.
     */
    public static function dispatchStatus(array $status, array $metaValue): self
    {
        return new self($status, $metaValue);
    }

    /**
     * Handle flow response webhook.
     */
    public static function dispatchFlow(array $flow, array $metaValue): self
    {
        $messageData = [
            'id' => $flow['flow_token'] ?? uniqid('flow_'),
            'type' => 'interactive_flow',
            'interactive' => ['nfm_reply' => $flow],
            'from' => $metaValue['contacts'][0]['wa_id'] ?? null,
            'timestamp' => now()->timestamp,
        ];

        return new self($messageData, $metaValue);
    }

    /**
     * Get or create WhatsApp contact.
     */
    protected function getOrCreateContact(array $messageData, array $metaValue): WhatsAppContact
    {
        $waId = $messageData['from'] ?? ($metaValue['contacts'][0]['wa_id'] ?? null);

        if (!$waId) {
            throw new \Exception('Unable to determine WhatsApp ID from message');
        }

        $profile = $metaValue['contacts'][0] ?? [];
        $phoneNumber = $profile['wa_id'] ?? $waId;

        return WhatsAppContact::findOrCreateByWhatsAppId($waId, $phoneNumber, $profile);
    }

    /**
     * Handle media download.
     */
    protected function handleMedia(WhatsAppMessage $message, array $messageData, WhatsAppGateway $gateway): void
    {
        $type = $messageData['type'];
        $mediaData = $messageData[$type] ?? [];

        if (!isset($mediaData['id'])) {
            return;
        }

        // Create media record
        $media = WhatsAppMedia::findOrCreateByWhatsAppId($mediaData['id'], [
            'mime_type' => $mediaData['mime_type'] ?? 'unknown',
            'file_size' => $mediaData['file_size'] ?? null,
        ]);

        // Update message with media ID
        $message->update(['media_id' => $media->id]);

        // Queue media download
        ProcessWhatsAppMedia::dispatch($media, $gateway)
            ->onQueue(config('whatsapp-vendor-concierge.queue.jobs.process_media'));
    }

    /**
     * Process message based on conversation state.
     */
    protected function processByState(
        WhatsAppConversation $conversation,
        WhatsAppContact $contact,
        WhatsAppMessage $message,
        VendorOnboardingService $onboardingService,
        ConversationManager $conversationManager,
        WhatsAppGateway $gateway
    ): void {
        $state = $conversation->state;
        $type = $message->type;
        $content = $message->content;

        // Log event
        OnboardingEvent::log(
            $conversation->onboarding_session_id ?? 0,
            $contact->id,
            'ai_interaction',
            $state,
            ['message_type' => $type, 'content' => $content]
        );

        match (true) {
            // New contact - show welcome
            $state === 'new' => $conversationManager->handleWelcome($conversation, $contact, $gateway),

            // Onboarding flow
            $state === 'welcome' => $conversationManager->startOnboarding($conversation, $contact, $gateway),
            $conversation->isOnboarding() => $onboardingService->processStep($conversation, $contact, $message, $gateway),

            // AI concierge for registered vendors
            $contact->isVendor() && $state === 'ai_active' => $conversationManager->handleAiMessage($conversation, $contact, $message, $gateway),

            // Human handoff
            $state === 'human_handoff' => $conversationManager->handleHumanHandoff($conversation, $contact, $message, $gateway),

            // Default: show help
            default => $conversationManager->showHelp($conversation, $contact, $gateway),
        };
    }
}