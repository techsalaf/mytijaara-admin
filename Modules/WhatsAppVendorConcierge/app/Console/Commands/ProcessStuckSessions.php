<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\WhatsAppVendorConcierge\app\Models\CredentialToken;
use Modules\WhatsAppVendorConcierge\app\Models\NotificationDelivery;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingEvent;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\PendingAction;

class ProcessStuckSessions extends Command
{
    protected $signature = 'whatsapp:process-stuck-sessions {--dry-run : Report candidates without changing them}';
    protected $description = 'Expire inactive onboarding sessions, recover abandoned notification leases, and expire stale tokens/actions';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        // 1. Expire stuck onboarding sessions
        $expiredSessions = 0;
        OnboardingSession::where('status', 'started')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now())
            ->orderBy('id')
            ->chunkById(100, function ($sessions) use (&$expiredSessions, $isDryRun) {
                foreach ($sessions as $session) {
                    if (!$isDryRun) {
                        $session->update(['status' => 'expired']);
                        OnboardingEvent::log($session->id, $session->contact_id, 'onboarding_expired', $session->current_step);
                    }
                    $expiredSessions++;
                }
            });

        // 2. Recover abandoned notification leases (>15 mins in processing)
        $recoveredDeliveries = 0;
        NotificationDelivery::where('status', 'processing')
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '<=', Carbon::now()->subMinutes(15))
            ->orderBy('id')
            ->chunkById(100, function ($deliveries) use (&$recoveredDeliveries, $isDryRun) {
                foreach ($deliveries as $delivery) {
                    if (!$isDryRun) {
                        $delivery->update([
                            'status' => 'pending',
                            'claimed_at' => null,
                            'last_error' => 'Lease abandoned and recovered by maintenance command',
                        ]);
                    }
                    $recoveredDeliveries++;
                }
            });

        // 3. Expire stale credential tokens
        $expiredTokens = 0;
        CredentialToken::whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now())
            ->orderBy('id')
            ->chunkById(100, function ($tokens) use (&$expiredTokens, $isDryRun) {
                foreach ($tokens as $token) {
                    if (!$isDryRun) {
                        $token->update(['revoked_at' => Carbon::now()]);
                    }
                    $expiredTokens++;
                }
            });

        // 4. Expire stale pending actions
        $expiredActions = 0;
        PendingAction::where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now())
            ->orderBy('id')
            ->chunkById(100, function ($actions) use (&$expiredActions, $isDryRun) {
                foreach ($actions as $action) {
                    if (!$isDryRun) {
                        $action->update(['status' => 'expired']);
                    }
                    $expiredActions++;
                }
            });

        $prefix = $isDryRun ? 'Would expire/recover: ' : 'Maintenance completed: ';
        $this->info("{$prefix}{$expiredSessions} stuck sessions, {$recoveredDeliveries} notification leases, {$expiredTokens} credential tokens, {$expiredActions} pending actions.");

        return self::SUCCESS;
    }
}
