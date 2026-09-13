<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class Preflight extends Command
{
    protected $signature = 'whatsapp:preflight {--strict : Fail when unimplemented high-risk features are enabled}';
    protected $description = 'Check non-secret production prerequisites for WhatsApp Vendor Concierge';

    public function handle(): int
    {
        $checks = [];
        $appUrl = (string) config('app.url');
        $checks['HTTPS APP_URL'] = app()->environment('production') ? str_starts_with($appUrl, 'https://') : true;
        $checks['Meta credentials configured'] = collect([
            'phone_number_id', 'business_account_id', 'access_token', 'app_id', 'app_secret', 'verify_token',
        ])->every(fn ($key) => filled(config("whatsapp-vendor-concierge.api.{$key}")));
        $checks['Asynchronous WhatsApp queue'] = config('whatsapp-vendor-concierge.queue.connection') !== 'sync';
        $disk = config('whatsapp-vendor-concierge.media.storage_disk');
        $checks['Private media disk'] = $disk !== 'public' && filled(config("filesystems.disks.{$disk}.driver"));
        $checks['Status templates configured'] = collect(config('whatsapp-vendor-concierge.messaging.templates', []))
            ->every(fn ($template) => filled($template));
        $checks['Hardening tables migrated'] = collect([
            'whatsapp_credential_tokens', 'whatsapp_pending_actions', 'whatsapp_notification_deliveries',
        ])->every(fn ($table) => Schema::hasTable($table));

        if ($this->option('strict')) {
            $checks['No unimplemented write features enabled'] = !config('whatsapp-vendor-concierge.features.product_creation')
                && !config('whatsapp-vendor-concierge.features.order_management');
        }

        foreach ($checks as $label => $passed) {
            $this->line(($passed ? '<info>PASS</info>' : '<error>FAIL</error>')." {$label}");
        }
        return collect($checks)->every(fn ($passed) => $passed) ? self::SUCCESS : self::FAILURE;
    }
}
