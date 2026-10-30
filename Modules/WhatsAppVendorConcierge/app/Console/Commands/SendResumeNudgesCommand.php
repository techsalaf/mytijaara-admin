<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;

class SendResumeNudgesCommand extends Command
{
    protected $signature = 'whatsapp:send-resume-nudges {--dry-run : Preview without sending} {--force : Attempt send even if status is pending}';
    protected $description = 'Send approved WhatsApp resume template to vendors outside the 24h window';

    public function handle(WhatsAppGateway $gateway): int
    {
        $this->info("Checking Meta template status for 'vendor_onboarding_resume'...");

        $api = config('whatsapp-vendor-concierge.api');
        $wabaId = $api['business_account_id'] ?? env('WHATSAPP_BUSINESS_ACCOUNT_ID');
        $token = $api['access_token'] ?? env('WHATSAPP_ACCESS_TOKEN');
        $version = $api['version'] ?? 'v21.0';

        $templateStatus = 'UNKNOWN';
        if ($wabaId && $token) {
            try {
                $response = Http::withToken($token)
                    ->timeout(15)
                    ->get("https://graph.facebook.com/{$version}/{$wabaId}/message_templates", [
                        'name' => 'vendor_onboarding_resume',
                    ]);

                if ($response->successful()) {
                    $data = $response->json('data', []);
                    $templateStatus = $data[0]['status'] ?? 'NOT_FOUND';
                } else {
                    $this->warn("Could not query Meta API: " . $response->body());
                }
            } catch (\Throwable $e) {
                $this->warn("Meta API check error: " . $e->getMessage());
            }
        }

        $this->info("Meta Template 'vendor_onboarding_resume' status: [{$templateStatus}]");

        if ($templateStatus !== 'APPROVED' && !$this->option('force')) {
            $this->warn("Template is currently {$templateStatus}. Meta typically approves within a few minutes to hours.");
            $this->warn("Run with --force to attempt sending regardless, or wait for Meta approval.");
            return self::SUCCESS;
        }

        $contacts = WhatsAppContact::orderBy('id', 'asc')->get();
        $dryRun = (bool) $this->option('dry-run');

        $sentCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($contacts as $contact) {
            $conversation = WhatsAppConversation::where('contact_id', $contact->id)->latest('id')->first();
            $session = $conversation?->onboarding_session_id ? OnboardingSession::find($conversation->onboarding_session_id) : null;
            $status = $session?->status ?? 'no_session';

            if ($status === 'submitted' || $contact->vendor_id) {
                $skippedCount++;
                continue;
            }

            // Check 24-hour window
            $lastInbound = DB::table('whatsapp_messages')
                ->where('conversation_id', $conversation?->id)
                ->where('direction', 'inbound')
                ->latest('id')
                ->first();

            $lastInboundTime = $lastInbound?->created_at ? \Carbon\Carbon::parse($lastInbound->created_at) : null;
            $isWithin24h = $lastInboundTime && $lastInboundTime->diffInHours(now()) < 24;

            if ($isWithin24h) {
                $this->line("Skipping {$contact->phone_number} (24h window active, use direct message)");
                $skippedCount++;
                continue;
            }

            $collected = $session?->collected_data ?? [];
            $businessName = $collected['business_name'] ?? $contact->display_name ?? 'Your Store';
            $ownerName = trim(($collected['f_name'] ?? '') . ' ' . ($collected['l_name'] ?? '')) ?: $contact->display_name ?: 'Business Owner';

            $this->line("Targeting {$contact->phone_number} ({$businessName})...");

            if ($dryRun) {
                $this->info("  [DRY RUN] Would send template 'vendor_onboarding_resume' to {$contact->phone_number}");
                $sentCount++;
                continue;
            }

            try {
                $components = [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $ownerName],
                            ['type' => 'text', 'text' => $businessName],
                        ],
                    ],
                ];

                $gateway->sendTemplateMessage(
                    $contact->phone_number,
                    'vendor_onboarding_resume',
                    $components,
                    'en'
                );

                $this->info("  -> Template sent to {$contact->phone_number}!");
                $sentCount++;
            } catch (\Throwable $e) {
                $this->error("  -> Failed sending to {$contact->phone_number}: " . $e->getMessage());
                $failedCount++;
            }
        }

        $this->newLine();
        $this->info("=== SUMMARY ===");
        $this->info("Sent: {$sentCount}");
        $this->info("Failed: {$failedCount}");
        $this->info("Skipped: {$skippedCount}");

        return self::SUCCESS;
    }
}
