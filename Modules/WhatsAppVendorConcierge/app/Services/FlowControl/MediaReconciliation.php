<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\FlowControl;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\WhatsAppVendorConcierge\app\Models\VendorFlowSession;

class MediaReconciliation
{
    public function run(bool $execute): array
    {
        $disk = Storage::disk('vendor_flow_private');
        $candidates = 0;
        $removed = 0;
        $examined = 0;
        foreach ($disk->getDriver()->listContents('sessions', true) as $entry) {
            if (! $entry->isFile()) {
                continue;
            }
            if (++$examined > 250) {
                break;
            }
            $path = $entry->path();
            if (! preg_match('~^sessions/([0-9]+)/[a-f0-9]+\.(jpg|png)$~D', $path, $match)) {
                continue;
            }
            DB::transaction(function () use ($disk, $path, $match, $execute, &$candidates, &$removed) {
                $s = VendorFlowSession::lockForUpdate()->find((int) $match[1]);
                if ($s && ($s->expires_at->isFuture() || $s->vendor_id || $s->consumed_at)) {
                    return;
                }
                if (DB::table('wa_vendor_flow_media')->where('path', $path)->exists() || $disk->lastModified($path) > now()->subDay()->timestamp) {
                    return;
                }
                $candidates++;
                if ($execute && $disk->delete($path)) {
                    $removed++;
                }
            });
        }

        return ['orphan_candidates' => $candidates, 'removed' => $removed, 'execute' => $execute, 'batch_limit' => 250, 'rule' => 'Unowned image objects older than 24 hours; active/registered session files are retained.'];
    }
}
