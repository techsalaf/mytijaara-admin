<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$api=config('whatsapp-vendor-concierge.api');$r=Illuminate\Support\Facades\Http::withToken($api['access_token'])->timeout(20)->get('https://graph.facebook.com/'.$api['version'].'/'.$api['business_account_id'].'/message_templates',['fields'=>'name,status,language,components','limit'=>100]);
foreach($r->json('data',[]) as $t){echo json_encode($t)."\n";}
