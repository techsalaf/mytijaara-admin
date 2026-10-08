<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\FlowControl;

use Illuminate\Support\Facades\DB;

class Analytics
{
    public function cohort(string $from, string $to, ?string $state = null): array
    {
        $sessions = DB::table('wa_vendor_flow_sessions')->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
        if ($state) {
            $sessions->where('state', $state);
        }
        $events = DB::table('wa_vendor_flow_events')->whereIn('flow_session_id', (clone $sessions)->select('id'))->selectRaw('event, COUNT(DISTINCT flow_session_id) AS sessions')->groupBy('event')->get();
        $started = (clone $sessions)->count();
        $completed = (clone $sessions)->whereNotNull('consumed_at')->count();
        $expr = DB::getDriverName() === 'sqlite' ? '(julianday(consumed_at)-julianday(created_at))*86400' : 'TIMESTAMPDIFF(SECOND,created_at,consumed_at)';
        $seconds = (clone $sessions)->whereNotNull('consumed_at')->selectRaw('AVG('.$expr.') AS seconds')->value('seconds');
        $chat = DB::table('onboarding_sessions')->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->whereNotIn('id', DB::table('wa_vendor_flow_sessions')->select('onboarding_session_id'));
        $chatStarted = (clone $chat)->count();
        $chatCompleted = (clone $chat)->whereNotNull('completed_at')->count();

        return ['from' => $from, 'to' => $to, 'started' => $started, 'completed' => $completed, 'completion_percent' => $started ? round(100 * $completed / $started, 2) : null, 'average_seconds' => $seconds !== null ? round((float) $seconds) : null, 'events' => $events, 'chat_started' => $chatStarted, 'chat_completed' => $chatCompleted, 'chat_completion_percent' => $chatStarted ? round(100 * $chatCompleted / $chatStarted, 2) : null];
    }
}
