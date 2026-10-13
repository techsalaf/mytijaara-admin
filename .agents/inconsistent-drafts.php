<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
foreach([24,28,15] as $id){$c=DB::table('whatsapp_conversations')->find($id);echo 'CONV '.json_encode(['id'=>$id,'session'=>$c->onboarding_session_id,'state'=>$c->state,'step'=>$c->current_step])."\n";foreach(DB::table('onboarding_sessions')->where('contact_id',$c->contact_id)->get() as $s){$d=json_decode($s->collected_data,true);echo json_encode(['session'=>$s->id,'status'=>$s->status,'step'=>$s->current_step,'business'=>$d['business_name']??null,'fields'=>count($d ?? []),'updated'=>$s->updated_at])."\n";}}

