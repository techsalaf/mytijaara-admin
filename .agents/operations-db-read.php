<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode(['environment'=>app()->environment(),'db_host'=>config('database.connections.'.config('database.default').'.host'),'db_name'=>config('database.connections.'.config('database.default').'.database')]).PHP_EOL;
foreach(['stores','vendors','onboarding_sessions','whatsapp_conversations'] as $t) echo $t.': '.(Illuminate\Support\Facades\Schema::hasTable($t)?Illuminate\Support\Facades\DB::table($t)->count():'missing').PHP_EOL;
