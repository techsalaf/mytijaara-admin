<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use App\Models\BusinessSetting;
use App\Models\Item;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\WhatsAppVendorConcierge\app\Mail\StoreProductUploadNudgeMail;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;

class SendActiveStoreOutreachCommand extends Command
{
    protected $signature = 'concierge:notify-active-stores
        {--channel=all : Channels to use (all|email|whatsapp)}
        {--store= : Optional specific store ID to target}
        {--force : Execute live delivery (defaults to dry-run preview if omitted)}';

    protected $description = 'Send product upload campaign emails and WhatsApp nudges to active stores ahead of launch';

    public function handle(WhatsAppGateway $gateway): int
    {
        $channel = strtolower((string) $this->option('channel') ?: 'all');
        $storeId = $this->option('store');
        $force = (bool) $this->option('force');

        $query = Store::where('status', 1)->with('vendor');
        if ($storeId) {
            $query->where('id', (int) $storeId);
        }

        $stores = $query->get();
        if ($stores->isEmpty()) {
            $this->warn('No active stores found matching criteria.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '=== Active Store Launch Outreach (%s Mode) ===',
            $force ? 'LIVE EXECUTION' : 'DRY RUN PREVIEW'
        ));
        $this->line("Target Stores: {$stores->count()} | Channels: {$channel}\n");

        if ($force && in_array($channel, ['all', 'email'], true)) {
            $this->configureSmtp();
        }

        $tableRows = [];
        $emailSuccess = 0;
        $waSuccess = 0;

        foreach ($stores as $store) {
            $vendor = $store->vendor;
            $vendorName = trim(($vendor?->f_name ?? '') . ' ' . ($vendor?->l_name ?? '')) ?: $store->name;
            $email = $vendor?->email ?? $store->email;
            $phone = preg_replace('/[^0-9]/', '', $store->phone ?? $vendor?->phone ?? '');
            $itemsCount = Item::where('store_id', $store->id)->count();

            $emailStatus = 'skipped';
            $waStatus = 'skipped';

            // 1. Email Channel
            if (in_array($channel, ['all', 'email'], true)) {
                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emailStatus = 'missing_valid_email';
                } elseif (!$force) {
                    $emailStatus = 'dry_run_ready';
                } else {
                    try {
                        $details = [
                            'store_id' => $store->id,
                            'store_name' => $store->name,
                            'vendor_name' => $vendorName,
                            'email' => $email,
                            'items_count' => $itemsCount,
                            'login_url' => 'https://dashboard.mytijaara.com/vendor/auth/login',
                        ];
                        Mail::to($email)->send(new StoreProductUploadNudgeMail($details));
                        $emailStatus = 'sent';
                        $emailSuccess++;
                        Log::info("Active store outreach email sent to {$email} (Store #{$store->id})");
                    } catch (\Throwable $e) {
                        $emailStatus = 'failed: ' . substr($e->getMessage(), 0, 40);
                        Log::error("Active store outreach email failed for {$email}", ['error' => $e->getMessage()]);
                    }
                }
            }

            // 2. WhatsApp Channel (Approved Meta Marketing Template)
            if (in_array($channel, ['all', 'whatsapp'], true)) {
                if (empty($phone) || strlen($phone) < 10) {
                    $waStatus = 'missing_phone';
                } elseif (!$force) {
                    $waStatus = 'dry_run_ready';
                } else {
                    try {
                        // Normalize MSISDN
                        $cleanPhone = str_starts_with($phone, '0') ? '234' . substr($phone, 1) : $phone;
                        if (!str_starts_with($cleanPhone, '234') && strlen($cleanPhone) === 10) {
                            $cleanPhone = '234' . $cleanPhone;
                        }

                        // Meta Approved Template: vendor_application_approved
                        // Body parameter {{1}}: Store Name
                        $components = [
                            [
                                'type' => 'body',
                                'parameters' => [
                                    ['type' => 'text', 'text' => $store->name],
                                ],
                            ],
                        ];

                        $gateway->clearLastSendResult();
                        $result = $gateway->sendTemplateMessage(
                            $cleanPhone,
                            'vendor_application_approved',
                            $components,
                            'en'
                        );

                        if (!empty($result['messages'][0]['id'])) {
                            $waStatus = 'sent: ' . substr($result['messages'][0]['id'], 0, 20);
                            $waSuccess++;
                            Log::info("Active store WhatsApp nudge sent to {$cleanPhone} (Store #{$store->id})");
                        } else {
                            $errMsg = $result['error']['message'] ?? 'Meta API rejected message';
                            $waStatus = 'failed: ' . substr($errMsg, 0, 30);
                            Log::warning("Active store WhatsApp nudge failed for {$cleanPhone}", ['result' => $result]);
                        }
                    } catch (\Throwable $e) {
                        $waStatus = 'failed: ' . substr($e->getMessage(), 0, 30);
                        Log::error("Active store WhatsApp nudge exception for {$phone}", ['error' => $e->getMessage()]);
                    }
                }
            }

            $tableRows[] = [
                $store->id,
                $store->name,
                $itemsCount,
                $email ?: 'N/A',
                $emailStatus,
                $phone ?: 'N/A',
                $waStatus,
            ];
        }

        $this->table(
            ['ID', 'Store Name', 'Items', 'Email', 'Email Status', 'Phone', 'WhatsApp Status'],
            $tableRows
        );

        if (!$force) {
            $this->warn("\nRun with --force to execute live delivery to all active stores.");
        } else {
            $this->info("\nOutreach complete! Emails Sent: {$emailSuccess} | WhatsApp Sent: {$waSuccess}");
        }

        return self::SUCCESS;
    }

    protected function configureSmtp(): void
    {
        $setting = BusinessSetting::where('key', 'mail_config')->first();
        if ($setting) {
            $conf = json_decode($setting->value, true);
            if (!empty($conf['host'])) {
                Config::set('mail.default', 'smtp');
                Config::set('mail.mailers.smtp.transport', 'smtp');
                Config::set('mail.mailers.smtp.host', $conf['host']);
                Config::set('mail.mailers.smtp.port', (int) ($conf['port'] ?? 465));
                Config::set('mail.mailers.smtp.encryption', $conf['encryption'] ?? 'ssl');
                Config::set('mail.mailers.smtp.username', $conf['username'] ?? $conf['email_id']);
                Config::set('mail.mailers.smtp.password', $conf['password'] ?? '');
                Config::set('mail.from.address', $conf['email_id'] ?? 'hello@mytijaara.com');
                Config::set('mail.from.name', $conf['name'] ?? 'MyTijaara');
            }
        }
    }
}
