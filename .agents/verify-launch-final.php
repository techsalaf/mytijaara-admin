<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Modules\WhatsAppVendorConcierge\app\Models\ConciergeRecoveryAudit;
foreach(ConciergeRecoveryAudit::where('action','like','vendor_access_20260926_%')->where('is_dry_run',false)->where('status','success')->get() as $a){echo json_encode(['action'=>$a->action,'status'=>$a->status,'provider_accepted'=>!empty($a->details['message_id'])]).PHP_EOL;}
echo json_encode(['policy'=>App\Services\StoreManagedDeliveryPolicy::mode(),'stores'=>App\Models\Store::with('module')->get()->map(fn($s)=>['id'=>$s->id,'module_id'=>$s->module_id,'delivery'=>(int)$s->sub_self_delivery]),'support'=>config('whatsapp-vendor-concierge.support.whatsapp_number')]).PHP_EOL;
