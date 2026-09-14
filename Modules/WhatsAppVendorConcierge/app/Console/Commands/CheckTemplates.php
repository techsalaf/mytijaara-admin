<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Modules\WhatsAppVendorConcierge\app\Jobs\SendVendorStatusNotification;

class CheckTemplates extends Command
{
    protected $signature = 'whatsapp:check-templates {--event=* : Limit checks to approved, denied, suspended or unsuspended}';
    protected $description = 'Read Meta templates and verify configured names, languages, approval and component counts without sending messages';

    public function handle(): int
    {
        $events = $this->option('event') ?: array_keys(SendVendorStatusNotification::BODY_PARAMETER_COUNTS);
        if (array_diff($events, array_keys(SendVendorStatusNotification::BODY_PARAMETER_COUNTS))) {
            $this->error('Unknown notification event.');
            return self::FAILURE;
        }
        $api = config('whatsapp-vendor-concierge.api');
        if (empty($api['business_account_id']) || empty($api['access_token'])) {
            $this->error('Meta WABA ID and access token are required.');
            return self::FAILURE;
        }
        $url = 'https://graph.facebook.com/'.$api['version'].'/'.$api['business_account_id'].'/message_templates';
        $failed = false;
        foreach ($events as $event) {
            $name = config("whatsapp-vendor-concierge.messaging.templates.{$event}");
            $locale = config("whatsapp-vendor-concierge.messaging.template_locales.{$event}");
            if (!$name || !$locale) {
                $this->error("FAIL {$event}: name or locale is not configured.");
                $failed = true;
                continue;
            }
            $templates = [];
            $after = null;
            try {
                do {
                    $response = Http::withToken($api['access_token'])->timeout(20)->get($url, array_filter([
                        'name' => $name, 'fields' => 'name,status,language,components', 'limit' => 100, 'after' => $after,
                    ]));
                    if (!$response->successful()) {
                        $this->error('Meta template lookup failed (HTTP '.$response->status().', code '.($response->json('error.code') ?? 'unknown').').');
                        return self::FAILURE;
                    }
                    $templates = array_merge($templates, $response->json('data', []));
                    $after = $response->json('paging.next') ? $response->json('paging.cursors.after') : null;
                } while ($after);
            } catch (\Throwable $e) {
                // Exception messages can contain request URLs/tokens. Emit only class.
                $this->error('Meta template lookup unavailable: '.get_class($e));
                return self::FAILURE;
            }
            $match = collect($templates)->first(fn ($t) => $t['name'] === $name && $t['language'] === $locale);
            $reason = null;
            if (!$match) {
                $languages = collect($templates)->where('name', $name)->pluck('language')->unique()->implode(', ');
                $reason = 'not found; available locales: '.($languages ?: 'none');
            } elseif ($match['status'] !== 'APPROVED') {
                $reason = 'Meta status is '.$match['status'];
            } else {
                $body = collect($match['components'] ?? [])->firstWhere('type', 'BODY');
                preg_match_all('/\{\{([^}]+)\}\}/', $body['text'] ?? '', $variables);
                $actual = array_values(array_unique($variables[1]));
                $expected = array_map('strval', range(1, SendVendorStatusNotification::BODY_PARAMETER_COUNTS[$event]));
                sort($actual);
                if ($actual !== $expected) {
                    $reason = 'body parameters do not match the sender (expected '.count($expected).', found '.count($actual).')';
                }
                foreach ($match['components'] ?? [] as $component) {
                    if ($component['type'] === 'HEADER' && (($component['format'] ?? '') !== 'TEXT' || str_contains($component['text'] ?? '', '{{'))) {
                        $reason = 'header requires parameters not provided by this sender';
                    }
                    foreach ($component['buttons'] ?? [] as $button) {
                        if (str_contains($button['url'] ?? '', '{{') || !in_array($button['type'], ['QUICK_REPLY', 'URL', 'PHONE_NUMBER'], true)) {
                            $reason = 'button requires parameters not provided by this sender';
                        }
                    }
                }
            }
            $failed = $failed || $reason !== null;
            $this->line(($reason ? 'FAIL' : 'PASS')." {$event}: {$name} / {$locale}".($reason ? " — {$reason}" : ' — approved, components match'));
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
