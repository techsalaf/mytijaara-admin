<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Services\Operations\ConciergeRecoveryService;

class ConciergeRecoverCommand extends Command
{
    protected $signature = 'whatsapp:concierge-recover 
        {--action= : Action to take (renudge_current_step, release_stale_handoff, reprocess_inbound)}
        {--conversation= : Target specific conversation ID}
        {--all-silenced : Target all silenced onboarding conversations}
        {--all-stale-handoffs : Target all stale human handoffs}
        {--dry-run : Preview without making state changes}
        {--force : Execute confirmed changes without prompt}';

    protected $description = 'Safely recover stuck or silenced WhatsApp concierge conversations';

    public function handle(ConciergeRecoveryService $recoveryService): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $isForce = (bool) $this->option('force');
        $action = $this->option('action');

        if (!$isDryRun && !$isForce) {
            $isDryRun = true;
            $this->warn("No --force flag passed. Defaulting to safe --dry-run mode.");
        }

        $targetIds = [];

        if ($convId = $this->option('conversation')) {
            $targetIds[] = (int) $convId;
        } elseif ($this->option('all-silenced')) {
            $action = $action ?: 'renudge_current_step';
            $targetIds = WhatsAppConversation::where('state', 'onboarding_active')
                ->whereNotNull('current_step')
                ->pluck('id')
                ->toArray();
        } elseif ($this->option('all-stale-handoffs')) {
            $action = $action ?: 'release_stale_handoff';
            $targetIds = WhatsAppConversation::where('state', 'human_handoff')
                ->where('updated_at', '<=', now()->subHours(2))
                ->pluck('id')
                ->toArray();
        }

        if (empty($targetIds)) {
            $this->error("No targets specified. Use --conversation=ID, --all-silenced, or --all-stale-handoffs.");
            return 1;
        }

        if (empty($action)) {
            $this->error("Action is required. Specify --action=renudge_current_step or --action=release_stale_handoff.");
            return 1;
        }

        $this->info("Processing " . count($targetIds) . " conversations for action '{$action}' (" . ($isDryRun ? "DRY RUN" : "LIVE EXECUTION") . ")...");

        $results = $recoveryService->bulkRecover($targetIds, $action, dryRun: $isDryRun, actor: 'cli');

        $this->line("Eligible: {$results['eligible_count']} | Excluded: {$results['excluded_count']} | Succeeded: {$results['success_count']} | Failed: {$results['failed_count']}");

        $rows = [];
        foreach ($results['items'] as $item) {
            $rows[] = [
                'ID' => $item['id'] ?? '-',
                'Phone' => $item['phone'] ?? '-',
                'Step' => $item['step'] ?? '-',
                'Status' => $item['status'] ?? 'unknown',
                'Message / Reason' => $item['message'] ?? ($item['reason'] ?? ($item['error'] ?? '-')),
            ];
        }

        $this->table(['ID', 'Phone', 'Step', 'Status', 'Details'], $rows);

        if ($isDryRun) {
            $this->info("\nDry run completed cleanly. Run with --force to execute changes.");
        } else {
            $this->info("\nLive recovery execution completed.");
        }

        return 0;
    }
}
