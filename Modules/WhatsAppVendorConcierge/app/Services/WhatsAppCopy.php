<?php
namespace Modules\WhatsAppVendorConcierge\app\Services;

/** Presentation only: never changes interactive IDs, approved templates or tool JSON. */
class WhatsAppCopy
{
    public static function format(string $text): string
    {
        $parts = preg_split('/(```[\s\S]*?```)/u', trim($text), -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as &$part) {
            if (str_starts_with($part, '```')) continue;
            $part = preg_replace('/\*\*([^*\n]+)\*\*/u', '*$1*', $part);
            $part = preg_replace('/^#{1,6}\s+(.+)$/mu', '*$1*', $part);
            $part = preg_replace('/^[ \t]*[-•]\s+/mu', '• ', $part);
            $part = preg_replace('/\n{3,}/u', "\n\n", $part);
        }
        unset($part);
        $text = implode('', $parts);
        if ($text !== '' && !preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text)) $text = "💬 ".$text;
        return $text;
    }
}
