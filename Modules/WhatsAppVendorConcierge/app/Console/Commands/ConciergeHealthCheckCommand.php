<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\WhatsAppVendorConcierge\app\Services\Operations\ConciergeRecoveryService;

class ConciergeHealthCheckCommand extends Command
{
    protected $signature = 'whatsapp:concierge-health-check';
    protected $description = 'Run periodic health check scan for WhatsApp Vendor Concierge and record snapshot';

    public function handle(ConciergeRecoveryService $recoveryService): int
    {
        $this->info("Running WhatsApp Concierge Health Check scan...");
        $check = $recoveryService->runHealthCheck('scheduled');

        $this->info("Health check recorded #{$check->id}:");
        $this->line("Active Onboarding: {$check->total_active_conversations}");
        $this->line("Waiting for Concierge: {$check->waiting_for_concierge}");
        $this->line("Waiting for User: {$check->waiting_for_user}");
        $this->line("Stale Handoffs: {$check->stale_human_handoff}");
        $this->line("Silenced Conversations: {$check->silenced_count}");
        $this->line("Stuck Conversations: {$check->stuck_count}");
        $this->line("Failed Outbounds: {$check->failed_sends_count}");

        return 0;
    }
}
