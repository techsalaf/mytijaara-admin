<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Storage};
use Modules\WhatsAppVendorConcierge\app\Models\{WhatsAppConversation,OnboardingSession,ConciergeRecoveryAudit};
$apply=in_array('--apply',$argv??[],true);
foreach([24,15] as $id){DB::transaction(function()use($id,$apply){
$c=WhatsAppConversation::lockForUpdate()->findOrFail($id);$s=OnboardingSession::lockForUpdate()->findOrFail($c->onboarding_session_id);
if($c->state!=='onboarding_active')throw new RuntimeException('Ownership changed');
$restore=$id===24?OnboardingSession::lockForUpdate()->findOrFail(33):$s;
if($id===24 && ($s->id!==34 || !empty($s->collected_data) || $restore->contact_id!==$c->contact_id || ($restore->collected_data['business_name']??'')!=='Jummy Beads and Accessories' || $restore->current_step!=='location'))throw new RuntimeException('Restore precondition changed');
if($id===15 && ($s->id!==19 || ($s->collected_data['business_name']??'')!=='List out the 5 section once' || $s->current_step!=='module_selection'))throw new RuntimeException('Repair precondition changed');
$step=$id===24?'location':'business_basics';$backup='private/maintenance/stalled-'.$id.'-'.now()->format('Ymd-His').'.json';
if($apply){Storage::disk('local')->put($backup,json_encode(['conversation'=>$c->getAttributes(),'session'=>$s->getAttributes(),'restored_session'=>$restore->getAttributes()],JSON_PRETTY_PRINT));
if($id===24)$s->update(['status'=>'abandoned']);
$restore->update(['status'=>'started','current_step'=>$step]);$c->update(['onboarding_session_id'=>$restore->id,'current_step'=>$step]);
ConciergeRecoveryAudit::create(['conversation_id'=>$c->id,'contact_id'=>$c->contact_id,'action'=>'repair_saved_application','initiated_by'=>'cli','previous_state'=>'onboarding_active','proposed_state'=>'onboarding_active','previous_step'=>$s->getOriginal('current_step'),'is_dry_run'=>false,'status'=>'success','reason'=>$id===24?'Restore saved business after ordinary address was misrouted into restart':'Ask for actual business name; navigation question was stored as name','details'=>['backup'=>$backup,'session_id'=>$restore->id,'step'=>$step],'correlation_id'=>'repair-20260927-'.$id]);}
echo json_encode(['conversation'=>$id,'apply'=>$apply,'session'=>$restore->id,'step'=>$step,'backup'=>$apply?$backup:null])."\n";
});}
