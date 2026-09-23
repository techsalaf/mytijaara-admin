<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;

class InboundPrivacy
{
    public static function redact(array $message, ?WhatsAppConversation $conversation = null): array
    {
        if (!$conversation && !empty($message['from'])) {
            $conversation = WhatsAppConversation::whereHas('contact', fn ($q) => $q->where('whatsapp_id', $message['from']))
                ->whereNotIn('state', ['closed', 'expired'])->latest()->first();
        }
        if ($conversation && ($conversation->current_step === 'account_password'
            || $conversation->onboardingSession?->current_step === 'account_password')) {
            // Preserve only exact navigation commands and known button IDs.
            // Never retain arbitrary text, titles, captions, or flow data.
            $button = $message['interactive']['button_reply']['id'] ?? null;
            $text = ConversationCommands::normalize((string) ($message['text']['body'] ?? $message['button']['payload'] ?? $message['button']['text'] ?? ''));
            $command = ConversationCommands::action($text);
            $message = array_intersect_key($message, array_flip(['id', 'from', 'timestamp']));
            $message['type'] = 'text';
            $message['text'] = ['body' => $command ? $text : '[redacted: credential step]'];
            if (in_array($button, ['resend_password_link', 'resume_onboarding', 'start_fresh', 'open_shop', 'start_onboarding', 'talk_support', 'check_status', 'learn_selling', 'faq'], true)) {
                unset($message['text']);
                $message['type'] = 'interactive';
                $message['interactive'] = ['button_reply' => ['id' => $button, 'title' => $button]];
            }
        }
        return $message;
    }
}
