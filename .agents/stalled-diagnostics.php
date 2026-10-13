<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$diag=app(Modules\WhatsAppVendorConcierge\app\Services\Operations\ConciergeDiagnosticService::class);
foreach($diag->scan() as $row){$d=$row['diag']; echo json_encode(array_intersect_key($d,array_flip(['conversation_id','state','current_step','failure_category','can_nudge','service_window_open','support_case_id','human_diagnosis']))+['id'=>$row['conversation']->id])."\n";}
