<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r=app(Modules\WhatsAppVendorConcierge\app\Services\Operations\ConciergeRecoveryService::class);
foreach(['renudge_current_step'=>[14,15,16,18,19,22], 'assign_human'=>[2,7,12], 'release_stale_handoff'=>[5]] as $action=>$ids){
 $result=$r->bulkRecover($ids,$action,true,'cli');foreach($result['items'] as &$item)unset($item['phone']);unset($item);echo json_encode($result).PHP_EOL;
}
