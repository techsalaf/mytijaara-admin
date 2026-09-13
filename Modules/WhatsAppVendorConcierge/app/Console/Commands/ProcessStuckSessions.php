<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingEvent;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;

class ProcessStuckSessions extends Command
{
    protected $signature = 'whatsapp:process-stuck-sessions {--dry-run : Report candidates without changing them}';
    protected $description = 'Expire inactive WhatsApp onboarding sessions after their recorded expiry';

    public function handle(): int
    {
        $count = 0;
        OnboardingSession::where('status', 'started')->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->orderBy('id')->chunkById(100, function ($sessions) use (&$count) {
                foreach ($sessions as $session) {
                    if (!$this->option('dry-run')) {
                        $session->update(['status' => 'expired']);
                        OnboardingEvent::log($session->id, $session->contact_id, 'onboarding_expired', $session->current_step);
                    }
                    $count++;
                }
            });
        $this->info(($this->option('dry-run') ? 'Would expire ' : 'Expired ').$count.' WhatsApp onboarding sessions.');
        return self::SUCCESS;
    }
}
