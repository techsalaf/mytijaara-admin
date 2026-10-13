<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$api=config('whatsapp-vendor-concierge.api');$name=config('whatsapp-vendor-concierge.templates.onboarding_resume', 'onboarding_resume_reminder');
$r=Illuminate\Support\Facades\Http::withToken($api['access_token'])->timeout(20)->get('https://graph.facebook.com/'.$api['version'].'/'.$api['business_account_id'].'/message_templates',['name'=>$name,'fields'=>'name,status,language,components','limit'=>100]);
echo json_encode(['http'=>$r->status(),'templates'=>$r->json('data',[])])."\n";
