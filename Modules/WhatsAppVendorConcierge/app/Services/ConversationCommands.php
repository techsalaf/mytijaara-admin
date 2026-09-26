<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

/** Deterministic navigation, shared by credential redaction and message routing. */
class ConversationCommands
{
    public static function normalize(string $text): string
    {
        return strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    public static function action(string $text): ?string
    {
        $text = self::normalize($text);
        foreach ([
            'restart' => ['start', 'reset', 'restart', 'start over', 'start fresh'],
            'register' => ['register', 'open shop', 'create shop', 'sell', 'start onboarding', 'become a vendor', 'i want to sell on mytijaara', 'i want to create a shop', 'open_shop'],
            'welcome' => ['menu', 'hi', 'hello', 'hey', 'cancel', 'assalamu alaikum', 'assalaamu alaikum'],
            'support' => ['support', 'human', 'agent', 'help desk', 'talk to support', 'i want to talk to support', 'talk_support'],
            'info' => ['faq', 'info', 'learn', 'how to sell', 'learn about selling'],
            'help' => ['help'],
            'shop_details' => ['show my shop details', 'show me my shop details', 'my shop details'],
            'status' => ['status', 'check status', 'my application', 'application status'],
            'resume' => ['continue', 'resume'],
            'resend' => ['resend link', 'resend password link'],
        ] as $action => $aliases) {
            if (in_array($text, $aliases, true)) {
                return $action;
            }
        }
        return null;
    }
}
