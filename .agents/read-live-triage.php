<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
foreach(DB::table('whatsapp_conversations')->whereNotIn('state',['closed','expired'])->get() as $c){
 $messages=DB::table('whatsapp_messages')->where('conversation_id',$c->id)->where('direction','inbound')->orderByDesc('created_at')->limit(3)->get(['id','raw_text','created_at']);
 foreach($messages as $m){$text=(string)$m->raw_text;if($c->current_step==='account_password' && !preg_match('/^(hi|hello|start|reset|restart|create shop|support|help|continue)$/i',trim($text)))$text='[credential-step content omitted]';$text=preg_replace('/https?:\/\/\S+/','[link omitted]',$text);$m->raw_text=mb_substr($text,0,180);}
 echo json_encode(['conversation'=>$c->id,'state'=>$c->state,'step'=>$c->current_step,'recent_inbound'=>$messages]).PHP_EOL;
}
foreach(['storage/framework/views','bootstrap/cache'] as $dir){$path=$dir.'/codex-write-probe-'.bin2hex(random_bytes(5));$ok=file_put_contents($path,'probe')===5;if($ok)unlink($path);echo json_encode(['write_probe'=>$dir,'ok'=>$ok]).PHP_EOL;}
echo json_encode(['app_now'=>now()->toIso8601String(),'db_now'=>DB::selectOne('select now() as time')->time]).PHP_EOL;
