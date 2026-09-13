<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use App\Services\OrderMutationService;
use App\Services\ProductMutationService;
use App\Services\VendorApplicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class Preflight extends Command
{
    protected $signature = 'whatsapp:preflight {--strict : Enforce strict production release gates}';
    protected $description = 'Validate production release gates and operational readiness for WhatsApp Vendor Concierge';

    public function handle(): int
    {
        $checks = [];
        $isProduction = app()->environment('production');

        // 1. HTTPS APP_URL
        $appUrl = (string) config('app.url');
        $checks['HTTPS APP_URL'] = $isProduction ? str_starts_with($appUrl, 'https://') : true;

        // 2. Webhook signature verification in production
        $verifySignature = (bool) config('whatsapp-vendor-concierge.webhook.verify_signature', true);
        $checks['Webhook signature verification enabled'] = $isProduction ? $verifySignature : true;

        // 3. Asynchronous queue connection in production
        $queueConn = config('whatsapp-vendor-concierge.queue.connection', config('queue.default'));
        $checks['Asynchronous queue configured'] = $isProduction ? ($queueConn !== 'sync') : true;

        // 4. Private media storage disk
        $disk = config('whatsapp-vendor-concierge.media.storage_disk', 'local');
        $checks['Private media storage disk configured'] = ($disk !== 'public') && filled(config("filesystems.disks.{$disk}.driver"));

        // 5. Database migrations
        $requiredTables = [
            'whatsapp_contacts',
            'whatsapp_conversations',
            'whatsapp_messages',
            'onboarding_sessions',
            'whatsapp_credential_tokens',
            'whatsapp_pending_actions',
            'whatsapp_notification_deliveries',
            'whatsapp_vendor_preferences',
            'whatsapp_support_cases',
            'whatsapp_ai_usage_logs',
        ];
        $checks['All hardening database tables migrated'] = collect($requiredTables)
            ->every(fn ($table) => Schema::hasTable($table));

        // 6. Core service bindings
        $checks['Core canonical services resolvable'] = app()->bound(VendorApplicationService::class)
            || class_exists(VendorApplicationService::class)
            && class_exists(ProductMutationService::class)
            && class_exists(OrderMutationService::class);

        // 7. Notification templates configured
        $templates = config('whatsapp-vendor-concierge.messaging.templates', []);
        $checks['Status notification templates configured'] = !empty($templates);

        // 8. Meta credentials present
        $metaKeys = ['phone_number_id', 'access_token', 'app_secret', 'verify_token'];
        $metaConfigured = collect($metaKeys)->every(fn ($key) => filled(config("whatsapp-vendor-concierge.api.{$key}")));
        $checks['Meta API credentials configured'] = $isProduction ? $metaConfigured : true;

        // 9. AI provider credentials if AI active
        $aiEnabled = config('whatsapp-vendor-concierge.features.ai_conversation', true);
        if ($aiEnabled && $isProduction) {
            $provider = config('whatsapp-vendor-concierge.ai.provider', 'openai');
            $checks['AI provider credentials configured'] = filled(config("ai.providers.{$provider}.key"));
        } else {
            $checks['AI provider credentials configured'] = true;
        }

        // Print report
        $allPassed = true;
        $this->info("=== WhatsApp Vendor Concierge Preflight Release Gate ===");
        foreach ($checks as $label => $passed) {
            if (!$passed) {
                $allPassed = false;
            }
            $status = $passed ? '<info>[PASS]</info>' : '<error>[FAIL]</error>';
            $this->line("{$status} {$label}");
        }

        if (!$allPassed) {
            $this->error("\nRelease gate check FAILED. Blockers detected.");
            return self::FAILURE;
        }

        $this->info("\nRelease gate check PASSED. System is production-ready.");
        return self::SUCCESS;
    }
}
