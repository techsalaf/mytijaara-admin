<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Services\Operations\ConciergeDiagnosticService;

class ConciergeDiagnoseCommand extends Command
{
    protected $signature = 'whatsapp:concierge-diagnose {--filter=all : Filter (all, silenced, stale_handoff, stuck)} {--conversation= : Specific conversation ID}';
    protected $description = 'Diagnose WhatsApp Vendor Concierge conversations and report stuck/silenced states';

    public function handle(ConciergeDiagnosticService $diagnosticService): int
    {
        $this->info("=== WhatsApp Vendor Concierge Diagnostic Scan ===");

        $overview = $diagnosticService->getOperationsOverview();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Active Onboarding', $overview['total_active_onboarding']],
                ['Waiting for Concierge (Silenced)', $overview['waiting_for_concierge']],
                ['Waiting for User Response', $overview['waiting_for_user']],
                ['In Human Handoff (Total)', $overview['in_human_handoff']],
                ['Stale Human Handoffs (>2h)', $overview['stale_human_handoff']],
                ['Validation Failure Loops', $overview['stuck_conversations']],
                ['Failed Outbound Sends (48h)', $overview['failed_outbounds']],
                ['Vendors Awaiting Approval', $overview['vendors_awaiting_approval']],
                ['Recently Recovered (24h)', $overview['recently_recovered']],
                ['Overall Health', strtoupper($overview['system_health'])],
            ]
        );

        $specificId = $this->option('conversation');
        if ($specificId) {
            $conv = WhatsAppConversation::with('contact')->find($specificId);
            if (!$conv) {
                $this->error("Conversation #{$specificId} not found.");
                return 1;
            }
            $this->displaySingleDiagnosis($conv, $diagnosticService);
            return 0;
        }

        $filter = $this->option('filter');
        $query = WhatsAppConversation::with('contact')->whereNotNull('last_activity_at')->latest('last_activity_at')->limit(50);
        $convs = $query->get();

        $rows = [];
        foreach ($convs as $conv) {
            $diag = $diagnosticService->diagnoseConversation($conv);

            if ($filter === 'silenced' && $diag['failure_category'] !== 'silenced_onboarding') continue;
            if ($filter === 'stale_handoff' && $diag['failure_category'] !== 'stale_human_handoff') continue;
            if ($filter === 'stuck' && !in_array($diag['failure_category'], ['silenced_onboarding', 'stale_human_handoff', 'validation_failure_loop'])) continue;

            $rows[] = [
                'ID' => $conv->id,
                'Phone' => $conv->contact?->phone_number ?? 'N/A',
                'State' => $conv->state,
                'Step' => $conv->current_step ?? '-',
                'Category' => $diag['failure_category'],
                'Safety' => $diag['safety_classification'],
                'Diagnosis' => \Illuminate\Support\Str::limit($diag['human_diagnosis'], 45),
                'Action' => $diag['recommended_action'],
            ];
        }

        if (empty($rows)) {
            $this->info("No conversations matching filter '{$filter}'.");
        } else {
            $this->table(['ID', 'Phone', 'State', 'Step', 'Category', 'Safety', 'Diagnosis', 'Recommended Action'], $rows);
        }

        return 0;
    }

    protected function displaySingleDiagnosis(WhatsAppConversation $conv, ConciergeDiagnosticService $diagnosticService): void
    {
        $diag = $diagnosticService->diagnoseConversation($conv);
        $this->line("\n=== Deep Diagnosis for Conversation #{$conv->id} ===");
        $this->line("Phone: {$conv->contact?->phone_number}");
        $this->line("State: {$conv->state} | Current Step: {$conv->current_step}");
        $this->line("Failure Category: {$diag['failure_category']}");
        $this->line("Human Diagnosis: {$diag['human_diagnosis']}");
        $this->line("Recommended Action: {$diag['recommended_action']}");
        $this->line("Safety Classification: {$diag['safety_classification']}");
        $this->line("Service Window Open: " . ($diag['service_window_open'] ? 'YES' : 'NO (Template required)'));
        $this->line("Can Re-nudge: " . ($diag['can_nudge'] ? 'YES' : 'NO'));
        $this->line("Nudges Sent So Far: {$diag['nudges_sent_count']}/3");
        $this->line("Correlation ID: {$diag['correlation_id']}\n");
    }
}
