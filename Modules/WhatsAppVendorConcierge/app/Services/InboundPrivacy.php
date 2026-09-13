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
            // Allow only a backend-known button ID. Titles, captions and flow data
            // can carry a pasted password and must not reach storage or job payloads.
            $button = $message['interactive']['button_reply']['id'] ?? null;
            $message = array_intersect_key($message, array_flip(['id', 'from', 'timestamp']));
            $message['type'] = 'text';
            $message['text'] = ['body' => '[redacted: credential step]'];
            if ($button === 'resend_password_link') {
                $message['type'] = 'interactive';
                $message['interactive'] = ['button_reply' => ['id' => $button, 'title' => 'Resend link']];
            }
        }
        return $message;
    }
}
