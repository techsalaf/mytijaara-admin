<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Modules\WhatsAppVendorConcierge\app\Models\{WhatsAppConversation,ConciergeRecoveryAudit};
use Modules\WhatsAppVendorConcierge\app\Services\{WhatsAppGateway,ConversationManager,SupportCaseService};
$live=false;$c=WhatsAppConversation::with('contact.vendor')->findOrFail(5);
$lock=Illuminate\Support\Facades\Cache::lock('concierge-recovery-contact-'.$c->contact_id,180);if(!$lock->get())throw new RuntimeException('Contact busy');
try{$c->refresh();$in=$c->messages()->where('direction','inbound')->latest('created_at')->first();$out=$c->messages()->where('direction','outbound')->latest('created_at')->first();
$eligible=$c->state==='ai_active' && (int)$c->contact->vendor?->status===1 && !$c->contact->is_blocked && !app(SupportCaseService::class)->getActiveCase($c->contact) && $in && $in->created_at->gt(now()->subHours(24)) && (!$out || $out->created_at->lt($in->created_at)) && !ConciergeRecoveryAudit::where('conversation_id',5)->where('action','restore_vendor_menu')->where('is_dry_run',false)->where('created_at','>=',now()->subDay())->exists();
$status=$eligible?'dry_run_passed':'excluded';$id=null;
if($eligible && $live){$g=app(WhatsAppGateway::class);$g->clearLastSendResult();app(ConversationManager::class)->handleExistingVendor($c,$c->contact,$g);$id=$g->lastSendResult()['messages'][0]['id']??null;$status=$id?'success':'failed';}
ConciergeRecoveryAudit::create(['conversation_id'=>5,'contact_id'=>$c->contact_id,'action'=>'restore_vendor_menu','initiated_by'=>'cli','previous_state'=>'ai_active','proposed_state'=>'ai_active','is_dry_run'=>!$live,'status'=>$status,'reason'=>'Answer the unanswered greeting after releasing the orphaned handoff; use the existing approved-vendor dashboard menu','details'=>['message_id'=>$id],'correlation_id'=>'REC-'.Illuminate\Support\Str::uuid()]);
echo json_encode(['conversation_id'=>5,'eligible'=>$eligible,'live'=>$live,'status'=>$status,'message_id'=>$id]).PHP_EOL;
}finally{$lock->release();}
