<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
echo json_encode(['orders'=>DB::table('orders')->whereIn('store_id',[1,2])->select('store_id','order_status',DB::raw('count(*) as count'))->groupBy('store_id','order_status')->get(),'campaigns'=>DB::table('item_campaigns')->whereIn('store_id',[1,2])->count(),'store_category_relations'=>DB::getSchemaBuilder()->getColumnListing('stores')]).PHP_EOL;
