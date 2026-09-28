<?php

namespace Modules\WhatsAppVendorConcierge\app\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\InboundReceipt;
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

    public function retryUntil(): \DateTimeInterface
    {
        // Lock contention must not exhaust three attempts while another message is still running.
        return now()->addMinutes(10);
    }

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
        // Album/status/unknown events without a user message must not answer draft questions.
        if (empty($this->messageData['type']) || in_array($this->messageData['type'], ['reaction','system','unsupported'], true)) return;
        $messageId = $this->messageData['id'] ?? null;
        if (!$messageId) return;
        $lock = null;
        $receipt = null;
        $contactLock = null;

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

            $receipt = InboundReceipt::where('whatsapp_message_id', $messageId)->first();
            // Historical receipts cannot prove whether their side effects completed.
            if ($existingMessage && !$receipt) {
                Log::info('Duplicate WhatsApp message ignored', [
                    'message_id' => $this->messageData['id'],
                ]);
                return;
            }

            $receipt ??= InboundReceipt::create(['whatsapp_message_id' => $messageId]);
            if (in_array($receipt->phase, ['completed', 'reviewed', 'needs_review'], true)) return;
            if ($receipt->phase === 'processing') {
                // A worker may have died after a mutation or external send. Never replay blindly.
                $receipt->update(['phase' => 'needs_review', 'error_type' => 'InterruptedProcessing']);
                return;
            }
            $receipt->increment('attempts');
            // Get or create contact
            $contact = $this->getOrCreateContact($this->messageData, $this->metaValue);

            $contactLock = \Illuminate\Support\Facades\Cache::lock('wa_inbound_contact_'.$contact->id, 150);
            if (!$contactLock->get()) {
                if ($this->job) { $this->release(15); return; }
                throw new \RuntimeException('Conversation is processing another message.');
            }
            // Get or create conversation
            $conversation = $existingMessage?->conversation ?? WhatsAppConversation::getOrCreateActive($contact->id);

            if ($contact->is_blocked) {
                $receipt->update(['phase' => 'completed', 'completed_at' => now()]);
                return;
            }
            $this->messageData = \Modules\WhatsAppVendorConcierge\app\Services\InboundPrivacy::redact($this->messageData, $conversation);
            $this->metaValue = array_intersect_key($this->metaValue, array_flip(['contacts', 'metadata']));

            // Log the message
            $message = $existingMessage ?? WhatsAppMessage::logInbound($conversation->id, $this->messageData, $this->metaValue);
            $version = hash('sha256', json_encode([$conversation->state, $conversation->current_step, $conversation->onboarding_session_id, $conversation->updated_at?->toISOString()]));
            if (($receipt->state_version && $receipt->state_version !== $version) || ($existingMessage && $conversation->messages()->where('direction', 'inbound')->where('id', '>', $message->id)->exists())) {
                $receipt->update(['phase' => 'needs_review', 'error_type' => 'ConversationAdvanced']);
                return;
            }
            $receipt->update(['message_id' => $message->id, 'state_version' => $version, 'error_type' => null]);

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

            $receipt->update(['phase' => 'processing']);
            // Process based on conversation state
            $this->processByState($conversation, $contact, $message, $onboardingService, $conversationManager, $gateway);

            // Update conversation activity
            $conversation->update([
                'last_activity_at' => now(),
                'expires_at' => now()->addMinutes(config('whatsapp-vendor-concierge.onboarding.max_inactive_minutes', 30)),
            ]);

            $receipt->update(['phase' => 'completed', 'completed_at' => now()]);
        } catch (\Throwable $e) {
            if ($receipt) {
                $receipt->update(['phase' => $receipt->phase === 'processing' ? 'needs_review' : 'received', 'error_type' => $e::class]);
            }
            Log::error('ProcessIncomingWhatsAppMessage failed', [
                'message_id' => $this->messageData['id'] ?? null,
                'exception' => get_class($e),
            ]);
            throw $e;
        } finally {
            $contactLock?->release();
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
            throw $e;
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

        if ($state === 'human_handoff' && !$this->handleHumanHandoffState($conversation, $contact, $message, $gateway, $content, $state)) {
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

        // Voice transcription must be backed by a separately verified provider
        // and confirmed by the vendor. Until then, acknowledge the note instead
        // of letting an empty audio payload repeat the current form question.
        if ($type === 'audio') {
            $gateway->sendTextMessage($contact->phone_number,
                "🎙️ I received your voice note. Voice transcription is not enabled for this concierge yet.\n\nPlease type the detail you want to send, or use *Support* for help. Your saved progress remains unchanged.");
            return;
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

        $productSelection = \Modules\WhatsAppVendorConcierge\app\Services\ProductListingPresenter::selection($message);
        if ($productSelection !== null && $vendor && (int)$vendor->status === 1) {
            $flow = app(\Modules\WhatsAppVendorConcierge\app\Services\ProductListingFlow::class);
            $draft = $flow->current($conversation);
            if (!$draft) {
                $gateway->sendTextMessage($contact->phone_number, 'This product draft is already closed. Open Add Products to start another.');
                return;
            }
            $answer = \Modules\WhatsAppVendorConcierge\app\Services\ProductListingPresenter::decode($draft,$productSelection);
            if ($answer !== null && preg_match('/^page:(\d+)$/',$answer,$page)) {
                $flow->sendReply($gateway,$contact->phone_number,$flow->prompt($draft),$conversation,(int)$page[1]);
            } else {
                $reply=$flow->receiveOrDefer($conversation,$contact,$message);
                $flow->sendReply($gateway,$contact->phone_number,$reply,$conversation);
            }
            return;
        }

        // 1. Check for interactive button replies (Meta payload or parsed message)
        $buttonId = $this->messageData['interactive']['button_reply']['id']
            ?? ($content['interactive']['button_reply']['id'] ?? null);

        if ($buttonId && preg_match('/^onb:(\d+):(cover_branding|kyc_documents):skip$/',$buttonId,$choice)) {
            if ((int)$conversation->onboarding_session_id !== (int)$choice[1] || $conversation->current_step !== $choice[2]) {
                $gateway->sendTextMessage($contact->phone_number,'That option is from an earlier question. Please use the latest message.');
                return;
            }
            $answer=clone $message;
            $answer->raw_text='skip';$answer->type='text';$answer->content=['text'=>'skip'];
            $onboardingService->processStep($conversation,$contact,$answer,$gateway);
            return;
        }

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

    /**
     * Handle incoming message when conversation is in human_handoff.
     * Returns true if handoff was released and message should continue processing.
     * Returns false if message was handled/queued for human support.
     */
    protected function handleHumanHandoffState(
        WhatsAppConversation $conversation,
        WhatsAppContact $contact,
        WhatsAppMessage $message,
        WhatsAppGateway $gateway,
        array $content,
        string &$state
    ): bool {
        $support = app(\Modules\WhatsAppVendorConcierge\app\Services\SupportCaseService::class);
        $activeCase = $support->getActiveCase($contact);

        // 1. Check for interactive button/list replies (e.g. user clicked a menu or action button)
        $buttonId = $this->messageData['interactive']['button_reply']['id']
            ?? ($content['interactive']['button_reply']['id'] ?? null);
        $listId = $this->messageData['interactive']['list_reply']['id']
            ?? ($content['interactive']['list_reply']['id'] ?? null);
        $hasInteractiveAction = ($buttonId !== null && $buttonId !== 'talk_support')
            || ($listId !== null && $listId !== 'talk_support');

        // 2. Check for explicit resume / restart / navigation commands
        $rawText = trim((string) ($message->raw_text ?? ''));
        $command = \Modules\WhatsAppVendorConcierge\app\Services\ConversationCommands::action($rawText);
        $isBreakoutCommand = in_array($command, [
            'restart', 'register', 'resume', 'welcome', 'info', 'help', 'status', 'manage_shop', 'resend'
        ], true);

        // 3. Check for active onboarding step requiring vendor input (e.g. credential/password step)
        $isOnboardingInput = !empty($conversation->onboarding_session_id)
            && !empty($conversation->current_step)
            && $conversation->onboardingSession?->canResume();

        // 4. Check if human handoff is stale (no operator reply for > 2 hours)
        $lastOperatorMsg = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where(function ($q) {
                $q->where('metadata->origin', 'human_operator')
                  ->orWhere('metadata->origin', 'human');
            })
            ->latest('created_at')
            ->first();

        $caseAgeHours = $activeCase ? $activeCase->created_at->diffInHours(now()) : 999;
        $convAgeHours = $conversation->updated_at ? $conversation->updated_at->diffInHours(now()) : 999;
        $isStaleHandoff = $lastOperatorMsg
            ? $lastOperatorMsg->created_at->lte(now()->subHours(2))
            : ($caseAgeHours >= 2 || $convAgeHours >= 2);

        // If user takes action OR handoff is stale, auto-release back to concierge!
        if ($hasInteractiveAction || $isBreakoutCommand || ($isOnboardingInput && $isStaleHandoff) || $isStaleHandoff) {
            \Illuminate\Support\Facades\Log::info('Auto-releasing human handoff for customer message', [
                'conversation_id' => $conversation->id,
                'phone' => $contact->phone_number,
                'has_interactive' => $hasInteractiveAction,
                'command' => $command,
                'is_stale' => $isStaleHandoff,
            ]);

            if ($activeCase) {
                $support->resolveCase($activeCase, 'Auto-released human handoff: customer resumed activity or handoff was stale (>2h)', $conversation);
            }

            $targetState = ($conversation->onboarding_session_id && $conversation->current_step)
                ? 'onboarding_active'
                : ($contact->isVendor() ? 'ai_active' : 'welcome');

            $conversation->transitionTo($targetState);
            $state = $targetState;
            return true; // continue processing in processByState
        }

        // Active human handoff: record message to support ticket
        if ($activeCase) {
            $support->appendCustomerMessage($activeCase, $rawText);
        }

        // If customer hasn't received an acknowledgment in the last 30 minutes, confirm receipt
        $recentAck = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('created_at', '>=', now()->subMinutes(30))
            ->exists();

        if (!$recentAck) {
            $gateway->sendTextMessage(
                $contact->phone_number,
                "👤 Our support team has received your message and will respond shortly.\n\nType *resume* or *restart* at any time if you'd like to return to the automated concierge."
            );
        }

        return false; // handoff handled, stop processing
    }

}
