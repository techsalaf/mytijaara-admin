<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach(Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection::with('definition')->where('is_active',1)->get() as $c)echo json_encode(['id'=>$c->id,'provider'=>$c->definition->slug??$c->definition->name,'base'=>$c->getBaseUrl(),'models'=>$c->models()->where('is_enabled',1)->get(['model_id','supports_vision'])]).PHP_EOL;
foreach(Illuminate\Support\Facades\DB::table('failed_jobs')->get(['id','failed_at','exception']) as $j)echo json_encode(['id'=>$j->id,'at'=>$j->failed_at,'error'=>strtok($j->exception,"\n")]).PHP_EOL;
