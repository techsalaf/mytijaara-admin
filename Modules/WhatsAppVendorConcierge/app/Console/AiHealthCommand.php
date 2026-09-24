<?php

namespace Modules\WhatsAppVendorConcierge\app\Console;

use Illuminate\Console\Command;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppAiProvider;

class AiHealthCommand extends Command
{
    protected $signature = 'whatsapp-concierge:ai-health';
    protected $description = 'Check AI routing health, provider connection readiness, and capabilities.';

    public function handle()
    {
        $this->info("Checking Modern AI Connections...");
        $modern = AiProviderConnection::all();
        $hasWorkingModern = false;

        if ($modern->isEmpty()) {
            $this->warn("No modern AI connections configured.");
        } else {
            foreach ($modern as $conn) {
                $status = $conn->isAvailable() ? "<info>Available</info>" : "<error>Unavailable</error>";
                $this->line("- {$conn->name}: {$status} (Status: {$conn->status})");
                if ($conn->isAvailable()) {
                    $hasWorkingModern = true;
                }
            }
        }

        $this->info("\nChecking Legacy AI Providers...");
        $legacy = WhatsAppAiProvider::activeAndWorking()->get();
        $hasWorkingLegacy = false;

        if ($legacy->isEmpty()) {
            $this->warn("No working legacy AI providers configured.");
        } else {
            foreach ($legacy as $prov) {
                $status = !empty($prov->api_key) ? "<info>Configured</info>" : "<comment>Empty Key</comment>";
                $this->line("- {$prov->name}: {$status}");
                if (!empty($prov->api_key)) {
                    $hasWorkingLegacy = true;
                }
            }
        }

        $this->info("\nRouting Summary:");
        if ($hasWorkingModern) {
            $this->info("System will route using MODERN OmniRoute system.");
        } elseif ($hasWorkingLegacy) {
            $this->info("System will fallback to LEGACY providers.");
        } else {
            $this->error("CRITICAL: No working AI routes available. Vendor Concierge will fail to reply.");
            return 1;
        }

        return 0;
    }
}
