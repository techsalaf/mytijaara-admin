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
use Modules\WhatsAppVendorConcierge\app\Services\ConversationCommands;

class ProcessIncomingWhatsAppMessage implements ShouldQueue, \Illuminate\Contracts\Queue\ShouldBeEncrypted
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
        $messageId = $this->messageData['id'] ?? null;
        $lock = null;

        if ($messageId) {
            $lock = \Illuminate\Support\Facades\Cache::lock('process_wa_msg_' . $messageId, 150);
            if (!$lock->get()) {
                Log::info('Duplicate concurrent WhatsApp message locked', ['message_id' => $messageId]);
                return;
            }
        }

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

            if ($contact->is_blocked) {
                return;
            }
            $this->messageData = \Modules\WhatsAppVendorConcierge\app\Services\InboundPrivacy::redact($this->messageData, $conversation);
            $this->metaValue = array_intersect_key($this->metaValue, array_flip(['contacts', 'metadata']));

            // Log the message
            $message = WhatsAppMessage::logInbound($conversation->id, $this->messageData, $this->metaValue);

            // Mark as read
            if (isset($this->messageData['id'])) {
                try {
                    $gateway->markAsRead($this->messageData['id'], $conversation->state !== 'human_handoff');
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Failed to mark WhatsApp message as read', [
                        'message_id' => $this->messageData['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

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
                'exception' => get_class($e),
            ]);
            throw $e;
        } finally {
            $lock?->release();
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

        // Queue media download safely
        try {
            ProcessWhatsAppMedia::dispatch($media)
                ->onQueue(config('whatsapp-vendor-concierge.queue.jobs.process_media'));
        } catch (\Throwable $e) {
            Log::error('ProcessWhatsAppMedia dispatch failed', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
        }
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

        if ($state === 'human_handoff') {
            $support = app(\Modules\WhatsAppVendorConcierge\app\Services\SupportCaseService::class);
            if ($case = $support->getActiveCase($contact)) {
                $support->appendCustomerMessage($case, (string) $message->raw_text);
            }
            return;
        }

        // Support must remain available from completed/rejected onboarding and template quick replies.
        $supportReply = strtolower(trim((string) ($this->messageData['button']['payload']
            ?? $this->messageData['button']['text']
            ?? $this->messageData['interactive']['button_reply']['id']
            ?? $message->raw_text ?? '')));
        if (ConversationCommands::action($supportReply) === 'support') {
            $conversationManager->initiateHumanHandoff($conversation, $contact, $gateway);
            return;
        }
        $vendor = $contact->vendor_id ? $contact->vendor : null;
        if ($vendor && $vendor->status === null) {
            // A submitted applicant must never re-enter an old draft step.
            // Support commands are handled above, even while review is pending.
            $conversation->update(['state' => 'onboarding_completed', 'current_step' => null, 'vendor_id' => $vendor->id]);
            $contact->update(['contact_type' => 'vendor']);
            $conversationManager->checkApplicationStatus($conversation, $contact, $gateway);
            return;
        }
        if ($vendor && $vendor->status !== null && (int) $vendor->status === 0) {
            $conversationManager->handleRejectedApplicant($conversation, $contact, $gateway);
            return;
        }
        if ($vendor && (int) $vendor->status === 1) {
            // Old review_submit/current_step pointers must not resubmit an approved application.
            $conversation->update(['state' => 'ai_active', 'current_step' => null, 'vendor_id' => $vendor->id]);
            $contact->update(['contact_type' => 'vendor']);
            $state = 'ai_active';
        }

        // Log event if onboarding session exists
        if (!empty($conversation->onboarding_session_id)) {
            OnboardingEvent::log(
                $conversation->onboarding_session_id,
                $contact->id,
                'ai_interaction',
                $state,
                ['message_type' => $type, 'content' => $content]
            );
        }

        // 1. Check for interactive button replies (Meta payload or parsed message)
        $buttonId = $this->messageData['interactive']['button_reply']['id']
            ?? ($content['interactive']['button_reply']['id'] ?? null);

        if ($buttonId) {
            $conversationManager->handleButtonResponse($conversation, $contact, $buttonId, $gateway);
            return;
        }

        // 2. Check for interactive list replies
        $listId = $this->messageData['interactive']['list_reply']['id']
            ?? ($content['interactive']['list_reply']['id'] ?? null);
        $listTitle = $this->messageData['interactive']['list_reply']['title']
            ?? ($content['interactive']['list_reply']['title'] ?? '');

        if ($listId) {
            $conversationManager->handleListResponse($conversation, $contact, $listId, $listTitle, $gateway);
            return;
        }

        // 3. AI Orchestration for intents and navigation
        $orchestrator = app(\Modules\WhatsAppVendorConcierge\app\Services\ConversationOrchestrator::class);
        if ($orchestrator->routeMessage($conversation, $contact, $message, $gateway)) {
            return;
        }

        // 4. State-based routing
        $hasActiveOnboarding = $conversation->isOnboarding()
            || (!empty($conversation->onboarding_session_id) && !empty($conversation->current_step))
            || (!empty($conversation->onboarding_session_id) && $state !== 'ai_active' && in_array($type, ['image', 'document']));

        if ($hasActiveOnboarding) {
            if ($conversation->state !== 'onboarding_active') {
                $conversation->update(['state' => 'onboarding_active']);
            }
            $onboardingService->processStep($conversation, $contact, $message, $gateway);
            return;
        }

        match (true) {
            // AI concierge for registered vendors
            $contact->isVendor() && $state === 'ai_active' => $conversationManager->handleAiMessage($conversation, $contact, $message, $gateway),

            // Human handoff mode
            $state === 'human_handoff' => $conversationManager->handleHumanHandoff($conversation, $contact, $message, $gateway),

            // Default for any unknown text or first contact
            default => $conversationManager->handleWelcome($conversation, $contact, $gateway),
        };
    }
}

