<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class FlowDiagnostics
{
    public function summary(): array
    {
        $out = ['enabled' => (bool) config('whatsapp-vendor-flow.enabled'), 'flow_id' => config('whatsapp-vendor-flow.flow_id'), 'definition_version' => config('whatsapp-vendor-flow.definition_version'), 'mode' => config('whatsapp-vendor-flow.mode')];
        if (! Schema::hasTable('wa_vendor_flow_sessions')) {
            return $out + ['migration' => 'not_applied'];
        }
        $out['synchronization'] = DB::table('wa_vendor_flow_sync')->select('definition_version', 'draft_flow_id', 'published_flow_id', 'status', 'error_code', 'updated_at')->where('definition_version', $out['definition_version'])->first();
        $out['recent_submissions'] = DB::table('wa_vendor_flow_receipts')->where('created_at', '>=', now()->subDay())->count();
        $out['states'] = DB::table('wa_vendor_flow_sessions')->selectRaw('state, COUNT(*) AS count')->groupBy('state')->get()->toArray();
        $out['expired_drafts'] = DB::table('wa_vendor_flow_sessions')->whereNull('consumed_at')->where('expires_at', '<', now())->count();
        $out['funnel'] = DB::table('wa_vendor_flow_events')->selectRaw('event, COUNT(DISTINCT flow_session_id) AS sessions')->groupBy('event')->get()->toArray();
        $counts = collect($out['funnel'])->pluck('sessions', 'event');
        $offered = (int) $counts->get('flow_offered', 0);
        $out['completion_rate_percent'] = $offered ? round(100 * (int) $counts->get('registration_completed', 0) / $offered, 2) : null;
        $out['completion_rate_basis'] = 'Distinct offered sessions; observed registration completion. Compare cohorts with the chat funnel separately.';
        $paths = DB::table('wa_vendor_flow_media')->pluck('path')->all();
        $out['orphaned_media'] = count(array_diff(Storage::disk('vendor_flow_private')->allFiles('sessions'), $paths));

        return $out;
    }
}
