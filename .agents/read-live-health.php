<?php
require getcwd().'/vendor/autoload.php'; $app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
echo json_encode(['cases'=>DB::table('whatsapp_support_cases')->whereIn('status',['open','in_progress'])->get(['id','contact_id','status','subject','created_at','updated_at','sla_expires_at'])]).PHP_EOL;
echo json_encode(['recent_messages'=>DB::table('whatsapp_messages as m')->whereRaw('m.id in (select max(id) from whatsapp_messages group by conversation_id,direction)')->get(['id','conversation_id','direction','type','status','created_at'])]).PHP_EOL;
echo json_encode(['recoveries'=>DB::table('concierge_recovery_audits')->orderByDesc('id')->limit(25)->get(['id','conversation_id','action','status','is_dry_run','created_at'])]).PHP_EOL;
$counts=[];$latest=[];$sent=[];
$f=new SplFileObject('storage/logs/laravel.log');foreach($f as $line){
if(preg_match('/^\[(2026-09-2[45][^]]*)\] \w+\.(ERROR|WARNING): (.*)/',$line,$m)) { $key=explode(' {',$m[3])[0]; $key=preg_replace('/https?:\/\/[^ ]+/','[url]',$key);$key=substr($key,0,150);$counts[$key]=($counts[$key]??0)+1;$latest[$key]=$m[1]; }
if(preg_match('/^\[(2026-09-2[45][^]]*)\].*WhatsApp message sent (\{.*\})/',$line,$m)){ $d=json_decode($m[2],true); $sent[$d['to']??'unknown']=['time'=>$m[1],'message_id'=>$d['message_id']??null,'type'=>$d['type']??null]; }
} arsort($counts);echo json_encode(['log_errors'=>$counts,'latest_error_at'=>$latest]).PHP_EOL;
foreach(DB::table('whatsapp_contacts')->get(['id','phone_number']) as $c) if(isset($sent[$c->phone_number])) echo json_encode(['contact_id'=>$c->id,'last_logged_send'=>$sent[$c->phone_number]]).PHP_EOL;
