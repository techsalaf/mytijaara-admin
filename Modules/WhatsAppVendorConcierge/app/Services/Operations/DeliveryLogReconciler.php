<?php
namespace Modules\WhatsAppVendorConcierge\app\Services\Operations;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Modules\WhatsAppVendorConcierge\app\Models\{WhatsAppContact, WhatsAppConversation, WhatsAppMessage, ConciergeRecoveryAudit};

class DeliveryLogReconciler
{
    /** Reconcile provider acceptance evidence only; never send or invent message content. */
    public function run(bool $dryRun = true, ?string $logPath = null): array
    {
        $lock = Cache::lock('concierge-delivery-reconciliation', 300);
        if (!$lock->get()) throw new \RuntimeException('A delivery reconciliation is already running.');
        $result = ['dry_run'=>$dryRun, 'eligible'=>0, 'imported'=>0, 'unmatched'=>0];
        try {
            $path = $logPath ?? storage_path('logs/laravel.log');
            if (!is_readable($path)) throw new \RuntimeException('Application log is not readable.');
            $contacts = WhatsAppContact::get()->keyBy(fn ($contact) => ltrim($contact->phone_number, '+'));
            $seen = [];
            foreach (new \SplFileObject($path) as $line) {
                if (!preg_match('/^\[([^]]+)\].*WhatsApp message sent (\{.*\})/', $line, $m)) continue;
                $entry = json_decode($m[2], true);
                $id = $entry['message_id'] ?? null;
                if (!$id || isset($seen[$id])) continue;
                $seen[$id] = true;
                if (WhatsAppMessage::where('whatsapp_message_id', $id)->exists()) continue;
                $contact = $contacts->get(ltrim($entry['to'] ?? '', '+'));
                if (!$contact) { $result['unmatched']++; continue; }
                $at = Carbon::parse($m[1]);
                $inbound = WhatsAppMessage::where('direction', 'inbound')->whereHas('conversation', fn ($q) => $q->where('contact_id', $contact->id))
                    ->where('created_at', '<=', $at)->latest('created_at')->latest('id')->first();
                if (!$inbound) { $result['unmatched']++; continue; }
                $result['eligible']++;
                if ($dryRun) continue;
                $message = WhatsAppMessage::logOutbound($inbound->conversation_id,
                    ['type'=>$entry['type'] ?? 'text', 'text'=>['body'=>'[Historical reply: message content was not recorded]']],
                    ['messages'=>[['id'=>$id]]]);
                $message->created_at = $at; $message->sent_at = $at;
                $message->metadata = array_merge($message->metadata ?? [], ['origin'=>'delivery_log_reconciliation', 'content_unavailable'=>true]);
                $message->save();
                $result['imported']++;
            }
            ConciergeRecoveryAudit::create(['action'=>'reconcile_delivery_logs', 'initiated_by'=>'cli', 'is_dry_run'=>$dryRun,
                'status'=>$dryRun ? 'dry_run_passed' : 'success', 'reason'=>'Reconcile missing provider acceptance records without sending messages',
                'details'=>$result, 'correlation_id'=>'LOG-'.\Illuminate\Support\Str::uuid()]);
            return $result;
        } finally { $lock->release(); }
    }
}
