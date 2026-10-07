<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\WhatsAppVendorConcierge\app\Models\VendorFlowSession;

class VendorFlowOperations extends Command
{
    protected $signature = 'whatsapp:flow-operations {--retry : Resume committed registrations and pending notifications} {--cleanup : Reconcile expired drafts and private orphan media} {--execute : Apply cleanup; otherwise dry-run}';

    protected $description = 'Admin-safe Flow diagnostics and bounded private-media reconciliation.';

    public function handle(\Modules\WhatsAppVendorConcierge\app\Services\FlowDiagnostics $diagnostics): int
    {
        $this->line(json_encode($diagnostics->summary(), JSON_PRETTY_PRINT));
        if ($this->option('retry')) {
            VendorFlowSession::whereNotNull('vendor_id')->where(function ($q) {
                $q->whereNull('consumed_at')->orWhere('notification_status', '!=', 'sent');
            })->orderBy('id')->chunkById(100, function ($sessions) {
                foreach ($sessions as $s) {
                    if (! $this->option('execute')) {
                        continue;
                    }try {
                        if (! $s->consumed_at) {
                            app(\Modules\WhatsAppVendorConcierge\app\Services\FlowSubmissionProcessor::class)->resumeRegistered($s->id);
                        } else {
                            \Modules\WhatsAppVendorConcierge\app\Jobs\SendFlowRegistrationNotification::dispatch($s->id)->afterCommit();
                        }
                    } catch (\Throwable $e) {
                        $this->warn('Registration recovery remains pending for session '.$s->id);
                    }
                }
            });
            $this->info($this->option('execute') ? 'Recovery dispatched.' : 'Recovery dry-run; no mutations.');
        }
        if (! $this->option('cleanup')) {
            return self::SUCCESS;
        }
        $count = 0;
        $execute = (bool) $this->option('execute');
        $disk = Storage::disk('vendor_flow_private');
        VendorFlowSession::whereNull('consumed_at')->where('expires_at', '<', now())->orderBy('id')->chunkById(100, function ($sessions) use (&$count, $execute, $disk) {
            foreach ($sessions as $candidate) {
                DB::transaction(function () use ($candidate, &$count, $execute, $disk) {
                    $s = VendorFlowSession::lockForUpdate()->find($candidate->id);
                    if (! $s || $s->consumed_at || $s->expires_at->isFuture() || $s->vendor_id) {
                        return;
                    }
                    $rows = DB::table('wa_vendor_flow_media')->where('flow_session_id', $s->id)->whereIn('state', ['staged', 'superseded'])->get();
                    $count += $rows->count();
                    if ($execute) {
                        foreach ($rows as $r) {
                            $disk->delete($r->path);
                            DB::table('wa_vendor_flow_media')->where('id', $r->id)->update(['state' => 'cleaned', 'updated_at' => now()]);
                        }
                        if ($s->state !== 'failed_terminal') {
                            app(\Modules\WhatsAppVendorConcierge\app\Services\FlowStateMachine::class)->event($s, 'expired');
                        }
                        $s->update(['state' => 'failed_terminal', 'draft' => null]);
                    }
                });
            }
        });
        VendorFlowSession::whereNotNull('consumed_at')->orderBy('id')->chunkById(100, function ($sessions) use (&$count, $execute, $disk) {
            foreach ($sessions as $candidate) {
                DB::transaction(function () use ($candidate, &$count, $execute, $disk) {
                    $s = VendorFlowSession::lockForUpdate()->find($candidate->id);
                    if (! $s || ! $s->consumed_at) {
                        return;
                    }
                    $prepared = DB::table('vendor_registration_media')->where('store_id', $s->store_id)->get();
                    if ($prepared->isEmpty() || $prepared->contains(fn ($r) => $r->state !== 'published')) {
                        return;
                    }
                    foreach ($prepared as $r) {
                        $public = Storage::disk(\App\CentralLogics\Helpers::getDisk());
                        $path = $r->directory.$r->name;
                        if (! $public->exists($path) || ! hash_equals($r->sha256, hash('sha256', $public->get($path)))) {
                            return;
                        }
                    }
                    foreach (DB::table('wa_vendor_flow_media')->where('flow_session_id', $s->id)->whereIn('state', ['promoted', 'superseded'])->get() as $r) {
                        $count++;
                        if ($execute) {
                            $disk->delete($r->path);
                            DB::table('wa_vendor_flow_media')->where('id', $r->id)->update(['state' => 'cleaned', 'updated_at' => now()]);
                        }
                    }
                    if ($execute) {
                        foreach ($prepared as $r) {
                            Storage::disk('registration_private')->delete($r->directory.$r->name);
                        }
                    }
                });
            }
        });
        foreach ($disk->allFiles('sessions') as $path) {
            if (! preg_match('~^sessions/([0-9]+)/[a-f0-9]+\.(jpg|png)$~D', $path, $matches)) {
                continue;
            }
            DB::transaction(function () use ($path, $matches, &$count, $execute, $disk) {
                $s = VendorFlowSession::lockForUpdate()->find((int) $matches[1]);
                if ($s && ($s->expires_at->isFuture() || $s->vendor_id || $s->consumed_at)) {
                    return;
                }
                if (DB::table('wa_vendor_flow_media')->where('path', $path)->exists() || $disk->lastModified($path) > now()->subMinutes(config('whatsapp-vendor-flow.session_minutes', 60))->timestamp) {
                    return;
                }
                $count++;
                if ($execute) {
                    $disk->delete($path);
                }
            });
        }
        $this->info(($execute ? 'Cleaned' : 'Dry-run candidates').': '.$count.' private media objects. Successful/registered attempts retained.');

        return self::SUCCESS;
    }
}
