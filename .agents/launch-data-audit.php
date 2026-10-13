<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;use Illuminate\Support\Facades\Schema;
foreach(['attributes','units','add_ons','order_cancel_reasons','refund_reasons','zones','module_zone','store_categories','tags','taxes','system_tax_setups','notification_settings'] as $t){if(Schema::hasTable($t))echo json_encode(['table'=>$t,'count'=>DB::table($t)->count()]).PHP_EOL;}
foreach(DB::table('business_settings')->whereIn('key',['product_approval_datas','order_confirmation_model','tax_included','commission','store_commission','mytijaara_store_managed_delivery'])->get() as $v)echo json_encode(['setting'=>$v->key,'value'=>$v->value]).PHP_EOL;
echo 'zones '.json_encode(DB::table('zones')->get(['id','name','status'])).PHP_EOL;
echo 'unit_schema '.json_encode(DB::select('SHOW COLUMNS FROM units')).PHP_EOL;
