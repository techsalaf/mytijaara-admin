<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$s=DB::table('stores')->where('name','like','%Jumaish%')->first(['id','vendor_id','name','store_business_model','self_delivery_system','delivery','take_away','zone_id','module_id']);echo json_encode(['store'=>$s]).PHP_EOL;
$c=DB::table('whatsapp_contacts')->where('vendor_id',$s->vendor_id)->first(['id']);$ids=DB::table('whatsapp_conversations')->where('contact_id',$c->id)->pluck('id');
echo json_encode(['conversations'=>DB::table('whatsapp_conversations')->whereIn('id',$ids)->get(['id','state','current_step','updated_at'])]).PHP_EOL;
echo json_encode(['messages'=>DB::table('whatsapp_messages')->whereIn('conversation_id',$ids)->orderByDesc('created_at')->limit(12)->get(['id','direction','raw_text','status','created_at'])]).PHP_EOL;
echo json_encode(['attempts'=>DB::table('ai_routing_attempts')->orderByDesc('id')->limit(10)->get(['connection_id','status','error_message','created_at'])]).PHP_EOL;
echo json_encode(['riders'=>DB::table('delivery_men')->count(),'confirmation'=>DB::table('business_settings')->where('key','order_confirmation_model')->value('value')]).PHP_EOL;
