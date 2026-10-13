<?php
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
function out($name,$data){echo json_encode([$name=>$data],JSON_UNESCAPED_SLASHES).PHP_EOL;}
out('stores',DB::table('stores as s')->leftJoin('vendors as v','v.id','=','s.vendor_id')->leftJoin('modules as m','m.id','=','s.module_id')->select('s.id','s.name','s.vendor_id','v.status as vendor_status','s.status','s.active','s.module_id','m.module_name','m.module_type','s.zone_id','s.store_business_model','s.created_at')->orderByDesc('s.id')->limit(25)->get());
out('applications',DB::table('onboarding_sessions')->select('id','contact_id','vendor_id','store_id','status','current_step','updated_at')->orderByDesc('id')->limit(45)->get());
out('conversations',DB::table('whatsapp_conversations as c')->leftJoin('whatsapp_contacts as p','p.id','=','c.contact_id')->select('c.id','c.contact_id','p.display_name','c.state','c.current_step','c.onboarding_session_id','c.vendor_id','p.is_blocked','p.opted_in_at','c.last_activity_at','c.updated_at')->orderByDesc('c.id')->limit(60)->get());
out('messages_by_direction',DB::table('whatsapp_messages')->select('direction','status',DB::raw('count(*) as count'))->groupBy('direction','status')->get());
out('module_config',config('module.current_module_id'));
out('cases',DB::table('whatsapp_support_cases')->select('id','contact_id','status','assigned_admin_id','created_at','updated_at')->whereIn('status',['open','in_progress'])->get());
