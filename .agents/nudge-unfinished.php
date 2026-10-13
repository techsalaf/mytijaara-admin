<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Services\Operations\ConciergeRecoveryService;
$apply=in_array('--apply',$argv??[],true);$service=app(ConciergeRecoveryService::class);
foreach([6,25,27,29,30,31] as $id){$r=$service->renudgeCurrentStep(WhatsAppConversation::findOrFail($id),!$apply,'cli',null,'Owner requested help with unfinished applications');echo json_encode(array_intersect_key($r,array_flip(['conversation_id','step','status','reason','message_id','service_window_open'])))."\n";}
