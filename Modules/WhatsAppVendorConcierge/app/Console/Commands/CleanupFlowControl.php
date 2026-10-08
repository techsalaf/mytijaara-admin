<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\ApplicationRecovery;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\Audit;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\MediaReconciliation;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\RuntimeSettings;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CleanupFlowControl extends Command
{
    protected $signature = 'whatsapp:flow-control-cleanup {--execute : Apply the explicitly configured cleanup policy}';

    protected $description = 'Bounded private-draft and analytics maintenance; evidence and admin audits are never deleted.';

    public function handle(): int
    {
        app(RuntimeSettings::class)->apply();
        $cadence = config('whatsapp-vendor-flow.cleanup_schedule', 'disabled');
        if ($cadence === 'disabled' || ! Schema::hasTable('wa_flow_control_audits')) {
            $this->info('Scheduled Flow cleanup disabled.');

            return self::SUCCESS;
        }
        $lock = Cache::lock('wa-flow-scheduled-cleanup', 300);
        if (! $lock->get()) {
            $this->info('Another cleanup owns the lease.');

            return self::SUCCESS;
        }
        try {
            $since = $cadence === 'hourly' ? now()->subHour() : now()->subDay();
            if (DB::table('wa_flow_control_audits')->where('action', 'scheduled_cleanup')->where('outcome', 'succeeded')->where('created_at', '>', $since)->exists()) {
                return self::SUCCESS;
            }
            $ids = DB::table('wa_vendor_flow_sessions')->whereNull('vendor_id')->whereNull('consumed_at')->where('state', '!=', 'failed_terminal')->where('expires_at', '<', now())->where('updated_at', '<', now()->subHours(config('whatsapp-vendor-flow.abandonment_hours', 24)))->orderBy('id')->limit(50)->pluck('id');
            $retention = config('whatsapp-vendor-flow.analytics_retention_days');
            // Durable credential completion lives on the session, not the analytics event stream.
            $events = $retention ? DB::table('wa_vendor_flow_events')->where('created_at', '<', now()->subDays($retention))->orderBy('id')->limit(500)->pluck('id') : collect();
            $result = ['expired_draft_candidates' => $ids->count(), 'expired_drafts_cleaned' => 0, 'analytics_candidates' => $events->count(), 'analytics_deleted' => 0];
            if ($this->option('execute')) {
                foreach ($ids as $id) {
                    try {
                        app(ApplicationRecovery::class)->perform($id, 'expire', 0);
                        $result['expired_drafts_cleaned']++;
                    } catch (HttpException) { /* State changed; retain it. */
                    }
                }
                $result['analytics_deleted'] = DB::table('wa_vendor_flow_events')->whereIn('id', $events)->delete();
            }
            $result += app(MediaReconciliation::class)->run((bool) $this->option('execute'));
            if ($this->option('execute')) {
                app(Audit::class)->record(0, 'scheduled_cleanup', null, 'succeeded', $result);
            }
            $this->line(json_encode($result));

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
