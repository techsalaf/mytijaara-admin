<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$c=DB::table('whatsapp_contacts')->where('vendor_id',2)->first();
foreach(DB::table('whatsapp_conversations')->where('contact_id',$c->id)->get() as $v){echo json_encode(['conversation'=>$v]).PHP_EOL;foreach(DB::table('whatsapp_messages')->where('conversation_id',$v->id)->latest('id')->limit(25)->get(['id','direction','type','content','media_id','status','created_at']) as $m)echo json_encode($m).PHP_EOL;}
echo 'MEDIA '.json_encode(DB::table('whatsapp_media')->where('contact_id',$c->id)->latest('id')->limit(5)->get()).PHP_EOL;
