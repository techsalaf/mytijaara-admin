<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$d=app(Modules\WhatsAppVendorConcierge\app\Services\Operations\ConciergeDiagnosticService::class);
echo json_encode(['overview'=>$d->getOperationsOverview()]).PHP_EOL;
foreach($d->scan() as $item){$x=$item['diag'];echo json_encode(array_intersect_key($x,array_flip(['conversation_id','state','current_step','failure_category','human_diagnosis','recommended_action','can_nudge','support_case_id','nudges_sent_count','service_window_open']))).PHP_EOL;}
config(['module.current_module_id'=>1,'default_pagination'=>25]);$class=new ReflectionClass(App\Http\Controllers\Admin\VendorController::class);$method=$class->getMethod('getNewStores');$controller=$class->newInstanceWithoutConstructor();
$pending=$method->invoke($controller,Illuminate\Http\Request::create('/admin/store/pending-requests'),null);
echo json_encode(['pending_controller_count'=>$pending->total(),'pending_store_ids'=>$pending->pluck('id')->all()]).PHP_EOL;
echo json_encode(['store_statuses'=>Illuminate\Support\Facades\DB::table('stores as s')->join('vendors as v','v.id','=','s.vendor_id')->whereIn('s.id',[4,5,6])->get(['s.id','s.name','s.module_id','v.status'])]).PHP_EOL;
$models=app(Modules\WhatsAppVendorConcierge\app\Services\AiRouterService::class)->resolveEligibleModels(true,false);
echo json_encode(['eligible_tool_model_count'=>$models->count(),'eligible_connection_ids'=>$models->pluck('connection_id')->unique()->values()]).PHP_EOL;
