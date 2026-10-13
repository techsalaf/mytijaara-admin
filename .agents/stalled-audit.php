<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
echo "DRAFTS\n";echo DB::table('whatsapp_product_drafts')->get(['id','conversation_id','status','step','data','errors','stalled_turns'])->toJson()."\n";
echo "TIJAARA MESSAGES\n";echo DB::table('whatsapp_messages')->where('conversation_id',5)->latest('id')->limit(32)->get(['id','direction','type','raw_text','media_id','created_at'])->toJson()."\n";
echo "CONVERSATIONS\n";echo DB::table('whatsapp_conversations')->orderByDesc('updated_at')->limit(40)->get(['id','contact_id','state','updated_at'])->toJson()."\n";
