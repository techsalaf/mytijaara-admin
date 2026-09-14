<?php

namespace Modules\WhatsAppVendorConcierge\app\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Ai;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Modules\WhatsAppVendorConcierge\app\Agents\VendorConciergeAgent;
use Modules\WhatsAppVendorConcierge\app\Agents\VendorAiContext;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Services\AiBudgetService;
use Modules\WhatsAppVendorConcierge\app\Services\LanguagePreferenceService;
use Modules\WhatsAppVendorConcierge\app\Services\NotificationPreferenceService;
use Modules\WhatsAppVendorConcierge\app\Services\SupportCaseService;

class RunVendorAiConversation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 30;
    public int $timeout = 90;

    public function __construct(
        public WhatsAppConversation $conversation,
        public WhatsAppContact $contact,
        public WhatsAppMessage $message,
    ) {
        $this->onConnection(config('whatsapp-vendor-concierge.queue.connection', 'database'));
        $this->onQueue(config('whatsapp-vendor-concierge.queue.jobs.run_ai_conversation', 'whatsapp.run_ai_conversation'));
    }

    public function handle(
        AiBudgetService $budgetService,
        LanguagePreferenceService $languageService,
        NotificationPreferenceService $prefService,
        SupportCaseService $supportService
    ): void {
        try {
            // Refresh conversation
            $this->conversation->refresh();
            $this->contact->refresh();
            if ($this->conversation->state !== 'ai_active' || $this->contact->is_blocked) {
                return;
            }

            // Extract text from incoming message
            $userText = $this->extractMessageText($this->message);
            if (!$userText) {
                Log::info('RunVendorAiConversation: No text to process', [
                    'conversation_id' => $this->conversation->id,
                ]);
                return;
            }

            $trimmed = trim($userText);

            // 1. Check deterministic notification preference commands (STOP, PAUSE ALERTS, etc.)
            $prefResult = $prefService->handleInboundCommand($this->contact, $trimmed);
            if ($prefResult !== null) {
                $this->sendReply($prefResult['message']);
                return;
            }

            // 2. Check deterministic language preference commands (CHANGE LANGUAGE, 1, 2, etc.)
            $langResult = $languageService->handleLanguageCommand($this->contact, $trimmed);
            if ($langResult !== null) {
                $this->sendReply($langResult['body']);
                return;
            }

            // 3. Check deterministic human support trigger
            if (preg_match('/^(support|agent|human|talk to support|help desk|reopen\b)/i', $trimmed)) {
                $activeCase = $supportService->getActiveCase($this->contact);
                if ($activeCase) {
                    $supportService->appendCustomerMessage($activeCase, $trimmed);
                    $this->sendReply("You have an active support ticket *#{$activeCase->ticket_id}* ({$activeCase->status}).\n\nYour message has been added to the ticket. A support representative will respond shortly.");
                } else {
                    $newCase = $supportService->createCase(
                        contact: $this->contact,
                        subject: 'Vendor requested human support via WhatsApp',
                        initialMessage: $trimmed,
                        conversation: $this->conversation
                    );
                    $this->sendReply("🎫 Support ticket *#{$newCase->ticket_id}* has been created for your request.\n\nA MyTijaara support specialist has been assigned and will follow up with you directly here. Thank you for your patience!");
                }
                return;
            }

            // 4. Resolve vendor and store
            $vendor = $this->contact->vendor;
            if (!$vendor) {
                Log::warning('RunVendorAiConversation: No vendor for contact', [
                    'contact_id' => $this->contact->id,
                ]);
                return;
            }

            $store = $vendor->store ?? $vendor->stores?->first();
            if (!$store) {
                Log::warning('RunVendorAiConversation: No store for vendor', [
                    'vendor_id' => $vendor->id,
                ]);
                return;
            }

            // 5. Check AI budget and circuit breaker
            $budgetCheck = $budgetService->canInvokeAi($this->contact, $vendor);
            if (!$budgetCheck['allowed']) {
                Log::warning('RunVendorAiConversation: AI budget/rate limit check failed', [
                    'reason' => $budgetCheck['reason'],
                    'contact_id' => $this->contact->id,
                    'vendor_id' => $vendor->id,
                ]);
                $this->sendReply($budgetCheck['fallback_message']);
                return;
            }

            // Record turn usage
            $budgetService->recordTurn($this->contact, $vendor);

            // Load and limit conversation history
            $rawHistory = $this->loadHistory();
            $history = $budgetService->limitHistory($rawHistory, AiBudgetService::MAX_HISTORY_TURNS);

            // Sanitize user text before sending to LLM (PII, credentials, bank accounts)
            $sanitizedUserText = $budgetService->sanitizePromptInput($userText);

            // Resolve persisted language preference
            $language = $languageService->getLanguage($this->contact);

            // Create context for this turn
            $context = new VendorAiContext($this->contact->id, $this->conversation->id);

            // Create agent
            $agent = new VendorConciergeAgent(
                context: $context,
                history: $history,
                vendor: $vendor,
                store: $store,
                language: $language,
            );

            // Configure AI provider & model
            $provider = config('whatsapp-vendor-concierge.ai.provider', 'openai');
            $model = config('whatsapp-vendor-concierge.ai.model', 'gpt-4o');

            // Fallback: If using OpenAI and no key in config/env, check core business settings
            if ($provider === 'openai' && empty(config('ai.providers.openai.key'))) {
                $openAiConfig = \App\CentralLogics\Helpers::get_business_settings('openai_config');
                if (!empty($openAiConfig['OPENAI_API_KEY'])) {
                    config(['ai.providers.openai.key' => $openAiConfig['OPENAI_API_KEY']]);
                }
            }

            if (empty(config("ai.providers.{$provider}.key"))) {
                Log::error('Vendor AI provider is not configured', [
                    'provider' => $provider,
                    'conversation_id' => $this->conversation->id,
                ]);
                $this->sendReply("The vendor assistant is temporarily unavailable.\n\nReply *MENU* to use the shop options, or *SUPPORT* to reach our team.");
                return;
            }

            // Send to AI and get response
            $response = $agent->prompt($sanitizedUserText, provider: $provider, model: $model, timeout: 60);

            $replyText = (string) $response->text;

            // Log AI token usage and estimated cost
            $promptTokens = (int) ($response->usage->promptTokens ?? 0);
            $completionTokens = (int) ($response->usage->completionTokens ?? 0);
            $estimatedCost = ($promptTokens * 0.000005) + ($completionTokens * 0.000015);

            $budgetService->logAiUsage(
                contact: $this->contact,
                vendor: $vendor,
                model: $model,
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                cost: $estimatedCost,
                isFallback: false
            );

            // Send the response via WhatsApp
            $this->sendReply($replyText);

            Log::info('Vendor AI conversation completed', [
                'conversation_id' => $this->conversation->id,
                'vendor_id' => $vendor->id,
                'tools_invoked' => $context->getToolsInvoked(),
                'response_length' => strlen($replyText),
            ]);

        } catch (\Throwable $e) {
            $budgetService->recordFailure($e);

            Log::error('RunVendorAiConversation failed', [
                'conversation_id' => $this->conversation->id,
                'exception' => get_class($e),
            ]);

            // Send error fallback message to vendor
            $this->sendReply("Sorry, I'm having trouble processing that right now. 😓\n\nPlease try again in a moment, or reply *SUPPORT* to connect directly with our support team.");

            // The vendor has received a fallback. Retrying would duplicate it and
            // turn a provider outage into a failed queue backlog.
            return;
        }
    }

    protected function sendReply(string $text): void
    {
        \Modules\WhatsAppVendorConcierge\app\Jobs\SendWhatsAppMessage::dispatch(
            $this->contact->phone_number,
            'text',
            ['body' => $text],
            $this->conversation->id
        )->onConnection(config('whatsapp-vendor-concierge.queue.connection', 'database'))
            ->onQueue(config('whatsapp-vendor-concierge.queue.jobs.send_message', 'whatsapp.send_message'));
    }

    /**
     * Load recent conversation history as Message objects.
     */
    protected function loadHistory(): array
    {
        return WhatsAppMessage::where('conversation_id', $this->conversation->id)
            ->when($this->message->id, fn ($q) => $q->where('id', '<', $this->message->id))
            ->whereIn('direction', ['inbound', 'outbound'])
            ->orderBy('created_at', 'desc')
            ->limit(config('whatsapp-vendor-concierge.ai.conversation_history_limit', 20))
            ->get()
            ->reverse()
            ->map(function ($msg) {
                $text = $this->extractMessageText($msg);
                if (!$text) return null;

                return $msg->direction === 'inbound'
                    ? new UserMessage($text)
                    : new \Laravel\Ai\Messages\AssistantMessage($text);
            })
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Extract text content from a WhatsApp message.
     */
    protected function extractMessageText($message): ?string
    {
        $content = $message->content;
        if (!empty($message->raw_text)) {
            return $message->raw_text;
        }
        if (is_array($content) && is_string($content['text'] ?? null)) {
            return $content['text'];
        }

        if (is_string($content)) {
            return $content;
        }

        if (is_array($content) || is_object($content)) {
            $content = (array) $content;

            // Text message
            if (!empty($content['body'])) {
                return $content['body'];
            }

            // Interactive button response
            if (!empty($content['interactive']['button_reply']['title'])) {
                return $content['interactive']['button_reply']['title'];
            }

            // Interactive list response
            if (!empty($content['interactive']['list_reply']['title'])) {
                return $content['interactive']['list_reply']['title'];
            }

            // Flow response
            if (!empty($content['interactive']['nfm_reply']['response_json'])) {
                return $content['interactive']['nfm_reply']['response_json'];
            }

            // Image/document caption
            if (!empty($content['caption'])) {
                return $content['caption'];
            }

            // Text nested
            if (!empty($content['text']['body'])) {
                return $content['text']['body'];
            }
        }

        return null;
    }
}
