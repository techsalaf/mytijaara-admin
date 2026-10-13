<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$rows=app(Modules\WhatsAppVendorConcierge\app\Services\Operations\ConciergeDiagnosticService::class)->scan();
echo json_encode(['reviewed'=>$rows->count(),'active_onboarding'=>$rows->where('diag.state','onboarding_active')->count(),'human_owned'=>$rows->filter(fn($r)=>$r['diag']['state']==='human_handoff')->map(fn($r)=>['conversation'=>$r['conversation']->id,'case'=>$r['diag']['support_case_id']])->values(),'queued_jobs'=>Illuminate\Support\Facades\DB::table('jobs')->count(),'failed_jobs'=>Illuminate\Support\Facades\DB::table('failed_jobs')->count()])."\n";
