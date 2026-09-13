<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Illuminate\Support\Facades\Log;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppVendorPreference;

class LanguagePreferenceService
{
    public const SUPPORTED_LANGUAGES = [
        'en' => 'English',
        'yo' => 'Yorùbá',
        'ha' => 'Hausa',
        'ig' => 'Igbo',
    ];

    public const DEFAULT_LOCALE = 'en';

    /**
     * Get persisted language preference for a contact.
     */
    public function getLanguage(WhatsAppContact $contact): string
    {
        $pref = WhatsAppVendorPreference::where('contact_id', $contact->id)->first();
        if ($pref && !empty($pref->locale) && array_key_exists($pref->locale, self::SUPPORTED_LANGUAGES)) {
            return $pref->locale;
        }

        $metaLocale = $contact->metadata['locale'] ?? null;
        if (!empty($metaLocale) && array_key_exists($metaLocale, self::SUPPORTED_LANGUAGES)) {
            return $metaLocale;
        }

        return self::DEFAULT_LOCALE;
    }

    /**
     * Persist explicit language choice for a contact.
     */
    public function setLanguage(WhatsAppContact $contact, string $locale): bool
    {
        $normalized = strtolower(trim($locale));
        if (!array_key_exists($normalized, self::SUPPORTED_LANGUAGES)) {
            return false;
        }

        $pref = WhatsAppVendorPreference::firstOrCreate(
            ['contact_id' => $contact->id],
            ['vendor_id' => $contact->vendor_id]
        );
        $pref->locale = $normalized;
        $pref->save();

        $meta = $contact->metadata ?? [];
        $meta['locale'] = $normalized;
        $contact->metadata = $meta;
        $contact->save();

        Log::info('Language preference updated', [
            'contact_id' => $contact->id,
            'locale' => $normalized,
        ]);

        return true;
    }

    /**
     * Check if inbound message is a language switch command or selection.
     */
    public function handleLanguageCommand(WhatsAppContact $contact, string $text): ?array
    {
        $cleaned = trim($text);
        $upper = strtoupper($cleaned);

        // Menu trigger
        if (in_array($upper, ['CHANGE LANGUAGE', 'LANGUAGE', 'SELECT LANGUAGE', 'LANG', 'EDE', 'ASUSU', 'HARSHEN'])) {
            return [
                'type' => 'menu',
                'body' => "🌐 *Choose Your Preferred Language / Yan Ede / Zaɓi Harshe / Họrọ Asụsụ*\n\n" .
                          "1️⃣ English\n" .
                          "2️⃣ Yorùbá\n" .
                          "3️⃣ Hausa\n" .
                          "4️⃣ Igbo\n\n" .
                          "Reply with 1, 2, 3, or 4 to confirm your choice.",
                'options' => self::SUPPORTED_LANGUAGES,
            ];
        }

        // Selection response
        $selection = match (strtolower($cleaned)) {
            '1', 'en', 'english' => 'en',
            '2', 'yo', 'yoruba', 'yorùbá' => 'yo',
            '3', 'ha', 'hausa' => 'ha',
            '4', 'ig', 'igbo' => 'ig',
            default => null,
        };

        if ($selection !== null) {
            $this->setLanguage($contact, $selection);
            $confirmations = [
                'en' => "✅ Language set to English. How can I help you today?",
                'yo' => "✅ A ti ṣeto ede si Yorùbá. Bawo ni mo ṣe le ran ọ lọwọ loni?",
                'ha' => "✅ An saita yare zuwa Hausa. Ta yaya zan iya taimaka maka a yau?",
                'ig' => "✅ E deela asụsụ gị na Igbo. Kedu ka m ga-esi nyere gị aka taa?",
            ];

            return [
                'type' => 'confirmation',
                'locale' => $selection,
                'body' => $confirmations[$selection] ?? $confirmations['en'],
            ];
        }

        return null;
    }

    /**
     * Optional suggestion when multi-word distinctive phrases are identified.
     * Never switches language automatically.
     */
    public function detectSuggestion(string $text): ?string
    {
        $cleaned = strtolower($text);

        // Distinctive Yoruba phrases (require at least 2 whole distinct words)
        $yoWords = ['bawo ni', 'ekaro', 'ekasan', 'e kaaro', 'se daadaa ni', 'ejo', 'ese pupo', 'mo fe'];
        $yoMatches = 0;
        foreach ($yoWords as $word) {
            if (str_contains($cleaned, $word)) {
                $yoMatches++;
            }
        }
        if ($yoMatches >= 2) {
            return 'yo';
        }

        // Distinctive Hausa phrases
        $haWords = ['sannu da zuwa', 'ina kwana', 'yaya dai', 'nagode', 'na gode', 'don allah', 'lafiya lau'];
        $haMatches = 0;
        foreach ($haWords as $word) {
            if (str_contains($cleaned, $word)) {
                $haMatches++;
            }
        }
        if ($haMatches >= 2) {
            return 'ha';
        }

        // Distinctive Igbo phrases
        $igWords = ['kedu ka ime', 'daalụ', 'daalu', 'biko nwanne', 'kedu', 'imela', 'nnoo'];
        $igMatches = 0;
        foreach ($igWords as $word) {
            if (str_contains($cleaned, $word)) {
                $igMatches++;
            }
        }
        if ($igMatches >= 2) {
            return 'ig';
        }

        return null;
    }
}
