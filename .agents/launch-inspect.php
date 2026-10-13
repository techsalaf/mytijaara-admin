<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
echo json_encode(['stores'=>DB::table('stores as s')->join('vendors as v','v.id','=','s.vendor_id')->get(['s.id','s.name','s.vendor_id','s.module_id','s.status','s.active','s.self_delivery_system','s.store_business_model','s.slug','v.status as vendor_status','v.email'])]).PHP_EOL;
echo json_encode(['modules'=>DB::table('modules')->get(['id','module_name','module_type','status'])]).PHP_EOL;
echo json_encode(['settings'=>DB::table('business_settings')->whereIn('key',['phone','email_address','business_name','web_app_url','order_confirmation_model'])->get(['key','value'])]).PHP_EOL;
echo json_encode(['items'=>DB::table('items')->select('store_id','module_id',DB::raw('count(*) as count'))->groupBy('store_id','module_id')->get()]).PHP_EOL;
echo json_encode(['app_url'=>config('app.url'),'mail_mailer'=>config('mail.default'),'support_config'=>config('whatsapp-vendor-concierge.support')]).PHP_EOL;
foreach(glob(dirname(getcwd()).'/.mytijaara-releases/*/journal/release.json') as $file){$j=json_decode(file_get_contents($file),true);echo json_encode(['release'=>basename(dirname(dirname($file))),'journal_keys'=>array_keys($j),'state'=>$j['status']??$j['state']??null]).PHP_EOL;}
