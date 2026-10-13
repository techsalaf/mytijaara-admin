<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Http};
echo json_encode(['login_setting'=>DB::table('data_settings')->where('key','store_login_url')->value('value'),'now'=>now()->toIso8601String()]).PHP_EOL;
$a=config('whatsapp-vendor-concierge.api');$r=Http::withToken($a['access_token'])->timeout(20)->get('https://graph.facebook.com/'.$a['version'].'/'.$a['business_account_id'].'/message_templates',['fields'=>'name,status,language,components','limit'=>100]);echo json_encode(['http'=>$r->status(),'templates'=>$r->json('data')]).PHP_EOL;
