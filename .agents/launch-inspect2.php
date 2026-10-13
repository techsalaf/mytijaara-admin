<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
echo json_encode(['items'=>DB::table('items')->whereIn('store_id',[1,2])->get(['id','name','category_id','category_ids','module_id'])]).PHP_EOL;
echo json_encode(['categories'=>DB::table('categories')->get(['id','name','module_id','parent_id'])]).PHP_EOL;
echo json_encode(['login'=>route('login',['tab'=>'vendor']),'mail_ready'=>config('mail.status'),'contact_windows'=>DB::table('whatsapp_contacts as c')->leftJoin('whatsapp_conversations as v','v.contact_id','=','c.id')->whereNotNull('c.vendor_id')->get(['c.id','c.vendor_id','c.opted_in_at','v.id as conversation_id','v.last_activity_at'])]).PHP_EOL;
echo json_encode(['possible_frontend'=>DB::table('business_settings')->where('key','like','%url%')->where('key','not like','%payment%')->get(['key','value'])]).PHP_EOL;
