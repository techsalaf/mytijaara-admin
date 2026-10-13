from pathlib import Path
r=Path('Modules/WhatsAppVendorConcierge')
p=r/'database/migrations/2026_09_27_140000_create_inbound_processing_receipts.php';s=p.read_text().replace("$table->string('error_type')->nullable();", "$table->string('error_type')->nullable();\n            $table->string('state_version', 64)->nullable();");p.write_text(s)
p=r/'app/Jobs/ProcessIncomingWhatsAppMessage.php';s=p.read_text().replace('        $receipt = null;', '        $receipt = null;\n        $contactLock = null;',1);s=s.replace('            // Get or create conversation',r'''            $contactLock = \Illuminate\Support\Facades\Cache::lock('wa_inbound_contact_'.$contact->id, 150);
            if (!$contactLock->get()) {
                if ($this->job) { $this->release(5); return; }
                throw new \RuntimeException('Conversation is processing another message.');
            }
            // Get or create conversation''',1)
s=s.replace("            $receipt->update(['message_id' => $message->id, 'error_type' => null]);",r'''            $version = hash('sha256', json_encode([$conversation->state, $conversation->current_step, $conversation->onboarding_session_id, $conversation->updated_at?->toISOString()]));
            if ($receipt->state_version && $receipt->state_version !== $version) {
                $receipt->update(['phase' => 'needs_review', 'error_type' => 'ConversationAdvanced']);
                return;
            }
            $receipt->update(['message_id' => $message->id, 'state_version' => $version, 'error_type' => null]);''',1)
s=s.replace('            $lock?->release();', '            $contactLock?->release();\n            $lock?->release();',1);p.write_text(s)
p=r/'app/Services/Operations/ConciergeDiagnosticService.php';s=p.read_text();s=s.replace("        $message = fn ($m)",r'''        $receipt = $in ? \Modules\WhatsAppVendorConcierge\app\Models\InboundReceipt::where('message_id', $in->id)->first() : null;
        if ($receipt && ($receipt->phase === 'needs_review' || ($receipt->phase === 'processing' && $receipt->updated_at->lt(now()->subMinutes(3))))) {
            $category = 'inbound_interrupted'; $responder = 'admin'; $safety = 'code_defect'; $action = 'investigate'; $canNudge = false;
            $diagnosis = 'Processing was interrupted after work may have started. Inspect the recorded action and reply before recovery; automatic replay is blocked to prevent duplicates.';
        } elseif ($receipt && $receipt->phase === 'received' && $receipt->updated_at->lt(now()->subMinutes(3))) {
            $category = 'inbound_retry_pending'; $responder = 'concierge'; $safety = 'code_defect'; $action = 'investigate'; $canNudge = false;
            $diagnosis = 'Message received but processing has not started. Check the queue; a checkpointed replay may be available.';
        }
        $message = fn ($m)''',1);s=s.replace("'failure_category'=>$category,", "'inbound_processing_phase'=>$receipt?->phase, 'inbound_attempts'=>$receipt?->attempts ?? 0,\n            'failure_category'=>$category,");p.write_text(s)
(r/'app/Console/Commands/ReplayInboundCommand.php').write_text(r'''<?php
namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;
use Illuminate\Console\Command;
use Modules\WhatsAppVendorConcierge\app\Models\{InboundReceipt, WhatsAppMessage};
use Modules\WhatsAppVendorConcierge\app\Jobs\ProcessIncomingWhatsAppMessage;
class ReplayInboundCommand extends Command {
    protected $signature = 'whatsapp:replay-inbound {message_id : Local inbound message ID} {--execute : Queue the reviewed replay; otherwise preview only}';
    protected $description = 'Preview or replay a recent message whose receipt proves business processing never started';
    public function handle(): int {
        $message = WhatsAppMessage::with('conversation.contact')->find($this->argument('message_id'));
        $receipt = $message ? InboundReceipt::where('message_id', $message->id)->first() : null;
        if (!$message || $message->direction !== 'inbound' || !$receipt || $receipt->phase !== 'received'
            || $message->created_at->lt(now()->subHours(24)) || !$message->conversation?->contact
            || $message->conversation->contact->is_blocked || str_contains((string) $message->raw_text, '[redacted:')) {
            $this->error('Replay excluded: missing/unsafe checkpoint, expired message, blocked contact or redacted credentials.');
            return self::FAILURE;
        }
        $conversation = $message->conversation;
        $version = hash('sha256', json_encode([$conversation->state, $conversation->current_step, $conversation->onboarding_session_id, $conversation->updated_at?->toISOString()]));
        if ($receipt->state_version !== $version) {
            $this->error('Conversation changed since receipt. Inspect the current step; do not replay an old answer.');
            return self::FAILURE;
        }
        $content = $message->content;
        $type = str_starts_with($message->type, 'interactive') ? 'interactive' : $message->type;
        if (!empty($content['button'])) $type = 'button';
        $payload = array_merge($content, ['id'=>$message->whatsapp_message_id, 'from'=>$conversation->contact->whatsapp_id, 'type'=>$type]);
        if ($type === 'text') $payload['text'] = ['body' => $message->raw_text];
        if ($this->option('execute')) {
            ProcessIncomingWhatsAppMessage::dispatch($payload, [])->onQueue(config('whatsapp-vendor-concierge.queue.jobs.process_incoming', 'whatsapp'));
            $this->info('Replay queued; the worker rechecks the checkpoint before processing.');
        } else $this->info('Eligible checkpoint. Add --execute after reviewing the conversation. No message was sent.');
        return self::SUCCESS;
    }
}
''',encoding='utf8')
p=r/'app/Providers/WhatsAppVendorConciergeServiceProvider.php';s=p.read_text().replace('        $this->commands([', '        $this->commands([\n            \\Modules\\WhatsAppVendorConcierge\\app\\Console\\Commands\\ReplayInboundCommand::class,');p.write_text(s)
