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
    ) {}

    public function handle(): void
    {
        try {
            // Refresh conversation
            $this->conversation->refresh();

            // Get vendor and store
            $vendor = $this->contact->vendor;
            if (!$vendor) {
                Log::warning('RunVendorAiConversation: No vendor for contact', [
                    'contact_id' => $this->contact->id,
                ]);
                return;
            }

            $store = $vendor->store;
            if (!$store) {
                Log::warning('RunVendorAiConversation: No store for vendor', [
                    'vendor_id' => $vendor->id,
                ]);
                return;
            }

            // Load conversation history (last N messages)
            $history = $this->loadHistory();

            // Build user message from incoming message
            $userText = $this->extractMessageText($this->message);
            if (!$userText) {
                Log::info('RunVendorAiConversation: No text to process', [
                    'conversation_id' => $this->conversation->id,
                ]);
                return;
            }

            // Detect language from message
            $language = $this->detectLanguage($userText);

            // Create context for this turn
            $context = new VendorAiContext();

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

            // Send to AI and get response
            $response = Ai::message([...$history, new UserMessage($userText)])
                ->usingProvider($provider)
                ->usingModel($model)
                ->withPrompt($agent)
                ->send();

            $replyText = (string) $response->text;

            // Send the response via WhatsApp
            \Modules\WhatsAppVendorConcierge\app\Jobs\SendWhatsAppMessage::dispatch(
                $this->contact->phone_number,
                'text',
                ['body' => $replyText],
                $this->conversation->id
            )->onQueue(config('whatsapp-vendor-concierge.queue.jobs.send_message', 'whatsapp-outbound'));

            Log::info('Vendor AI conversation completed', [
                'conversation_id' => $this->conversation->id,
                'vendor_id' => $vendor->id,
                'tools_invoked' => $context->getToolsInvoked(),
                'response_length' => strlen($replyText),
            ]);

        } catch (\Throwable $e) {
            Log::error('RunVendorAiConversation failed', [
                'conversation_id' => $this->conversation->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Send error message to vendor
            \Modules\WhatsAppVendorConcierge\app\Jobs\SendWhatsAppMessage::dispatch(
                $this->contact->phone_number,
                'text',
                [
                    'body' => "Sorry, I'm having trouble understanding right now. 😓\n\n" .
                             "Please try again in a moment, or say \"talk to support\" to connect with our team."
                ],
                $this->conversation->id
            )->onQueue(config('whatsapp-vendor-concierge.queue.jobs.send_message', 'whatsapp-outbound'));

            throw $e;
        }
    }

    /**
     * Load recent conversation history as Message objects.
     */
    protected function loadHistory(): array
    {
        return WhatsAppMessage::where('conversation_id', $this->conversation->id)
            ->whereIn('direction', ['inbound', 'outbound'])
            ->orderBy('created_at', 'desc')
            ->limit(20)
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

    /**
     * Detect language from message text.
     * Returns ISO code: en, ha, yo, ig, pcm
     */
    protected function detectLanguage(string $text): string
    {
        $text = strtolower($text);

        // Pidgin markers
        $pidgin = ['wetin', 'dey', 'una', 'abeg', 'oya', 'naija', 'biko', 'abia'];
        foreach ($pidgin as $marker) {
            if (str_contains($text, $marker)) {
                return 'pcm';
            }
        }

        // Hausa markers
        $hausa = ['yaya', 'naka', 'zan', 'don', 'ina', 'me', 'kuma', 'wannan'];
        foreach ($hausa as $marker) {
            if (str_contains($text, $marker)) {
                return 'ha';
            }
        }

        // Yoruba markers
        $yoruba = ['bawo', 'jowo', 'mo', 'fun', 'ni', 'ti', 'ko', 'wa'];
        foreach ($yoruba as $marker) {
            if (str_contains($text, $marker)) {
                return 'yo';
            }
        }

        // Igbo markers
        $igbo = ['kedu', 'biko', 'maka', 'nke', 'gi', 'ya', 'm', 'na'];
        foreach ($igbo as $marker) {
            if (str_contains($text, $marker)) {
                return 'ig';
            }
        }

        // Default to English
        return 'en';
    }
}