<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Modules\WhatsAppVendorConcierge\app\Models\{WhatsAppConversation,ConciergeRecoveryAudit};
use Modules\WhatsAppVendorConcierge\app\Services\{ProductListingFlow,ProductListingPresenter,WhatsAppGateway};
$c=WhatsAppConversation::findOrFail(5);$diag=app(Modules\WhatsAppVendorConcierge\app\Services\Operations\ConciergeDiagnosticService::class)->diagnoseConversation($c);
if(!class_exists(ProductListingPresenter::class) || !$diag['service_window_open'] || $c->state!=='ai_active' || $c->contact->is_blocked || app(ProductListingFlow::class)->current($c))throw new RuntimeException('Follow-up precondition changed');
if(ConciergeRecoveryAudit::where('correlation_id','product-photo-followup-20260927')->exists()){echo "Already sent\n";exit;}
$item=App\Models\Item::findOrFail(2);if($item->store_id!=2)throw new RuntimeException('Unexpected item');
$gateway=app(WhatsAppGateway::class);$gateway->clearLastSendResult();
app(ProductListingFlow::class)->sendReply($gateway,$c->contact->phone_number,'Product #2: Thinkplus Ear Pod 3 is already created and awaiting admin approval. Your photos and variations are saved. We have improved the photo step with a Done button and a saved-photo count. Thank you for testing!',$c);
$result=$gateway->lastSendResult();if(empty($result['messages'][0]['id']))throw new RuntimeException('Follow-up not accepted');
ConciergeRecoveryAudit::create(['conversation_id'=>$c->id,'contact_id'=>$c->contact_id,'action'=>'product_photo_followup','initiated_by'=>'cli','previous_state'=>$c->state,'proposed_state'=>$c->state,'is_dry_run'=>false,'status'=>'success','reason'=>'Owner requested recovery of photo listing loop','details'=>['message_id'=>$result['messages'][0]['id'],'item_id'=>2],'correlation_id'=>'product-photo-followup-20260927']);
echo json_encode(['accepted'=>true,'item_id'=>2,'type'=>'interactive_buttons'])."\n";
