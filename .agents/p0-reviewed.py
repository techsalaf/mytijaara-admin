from pathlib import Path
r=Path('Modules/WhatsAppVendorConcierge')
p=r/'app/Console/Commands/AiTestModelsCommand.php';s=p.read_text().replace("{--tools : Verify a harmless real tool call as well as text}'", "{--tools : Verify a harmless real tool call as well as text} {--model= : Limit the probe to one enabled model ID}'").replace("        if ($models->isEmpty())", "        if ($this->option('model')) $models = $models->where('model_id', $this->option('model'));\n        if ($models->isEmpty())");p.write_text(s)
p=r/'app/Jobs/ProcessIncomingWhatsAppMessage.php';s=p.read_text().replace("['completed', 'needs_review']", "['completed', 'reviewed', 'needs_review']");p.write_text(s)
(r/'app/Console/Commands/ReviewInboundCommand.php').write_text(r'''<?php
namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache, DB};
use Modules\WhatsAppVendorConcierge\app\Models\{InboundReceipt, WhatsAppMessage, ConciergeRecoveryAudit};
class ReviewInboundCommand extends Command {
    protected $signature = 'whatsapp:review-inbound {message_id} {--reason= : Outcome of the manual review; no secrets} {--execute : Record the review without replaying the message}';
    protected $description = 'Acknowledge an interrupted inbound after checking its actual effects; never resend or repeat actions';
    public function handle(): int {
        $message=WhatsAppMessage::with('conversation')->find($this->argument('message_id'));
        if (!$message || $message->direction !== 'inbound') { $this->error('Inbound message not found.'); return self::FAILURE; }
        $lock=Cache::lock('process_wa_msg_'.$message->whatsapp_message_id,150);
        if (!$lock->get()) { $this->error('Message is still being processed.'); return self::FAILURE; }
        try {
            $receipt=InboundReceipt::where('message_id',$message->id)->first();
            if (!$receipt || !in_array($receipt->phase,['needs_review','processing'],true) || ($receipt->phase==='processing' && $receipt->updated_at->gt(now()->subMinutes(3)))) {
                $this->error('This receipt is not eligible for manual acknowledgement.'); return self::FAILURE;
            }
            if (!$this->option('execute')) { $this->info('Review required: inspect changes and delivery evidence, then provide --reason and --execute. No action performed.'); return self::SUCCESS; }
            $reason=trim((string)$this->option('reason'));
            if (strlen($reason)<10 || strlen($reason)>500) { $this->error('Provide a review outcome between 10 and 500 characters.'); return self::FAILURE; }
            DB::transaction(function()use($receipt,$message,$reason){
                ConciergeRecoveryAudit::create(['conversation_id'=>$message->conversation_id,'contact_id'=>$message->conversation->contact_id,'action'=>'acknowledge_inbound','initiated_by'=>'console','previous_state'=>$message->conversation->state,'proposed_state'=>$message->conversation->state,'previous_step'=>$message->conversation->current_step,'is_dry_run'=>false,'status'=>'success','reason'=>$reason,'details'=>['message_id'=>$message->id,'previous_phase'=>$receipt->phase,'replayed'=>false],'correlation_id'=>'INBOUND-'.$receipt->id]);
                $receipt->update(['phase'=>'reviewed']);
            });
            $this->info('Review recorded. No message sent, replayed or business data changed.');
            return self::SUCCESS;
        } finally { $lock->release(); }
    }
}
''',encoding='utf8')
p=r/'app/Providers/WhatsAppVendorConciergeServiceProvider.php';s=p.read_text().replace('        $this->commands([', '        $this->commands([\n            \\Modules\\WhatsAppVendorConcierge\\app\\Console\\Commands\\ReviewInboundCommand::class,');p.write_text(s)
p=r/'app/Services/Operations/ConciergeDiagnosticService.php';s=p.read_text().replace("($in?->id ?? 0),", "($receipt?->message_id ?? $in?->id ?? 0),");p.write_text(s)
