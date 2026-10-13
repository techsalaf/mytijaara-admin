<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
echo json_encode(['connections'=>DB::table('ai_provider_connections')->get(['id','definition_id','name','is_active','status','consecutive_failures','cooldown_until','base_url_override'])]).PHP_EOL;
echo json_encode(['models'=>DB::table('ai_provider_models')->where('is_enabled',1)->get(['id','connection_id','model_id','supports_tool_calling','supports_vision'])]).PHP_EOL;
echo json_encode(['definitions'=>DB::table('ai_provider_definitions')->get(['id','slug','adapter_class'])]).PHP_EOL;
foreach(DB::table('ai_routing_attempts')->orderByDesc('id')->limit(6)->get(['connection_id','status','error_message','created_at']) as $e){ $e->error_message=preg_replace('/(sk-|AIza)[a-zA-Z0-9_-]+/','[redacted]',substr($e->error_message??'',0,350));echo json_encode($e).PHP_EOL; }
