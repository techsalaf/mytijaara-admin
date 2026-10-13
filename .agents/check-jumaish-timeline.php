<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
echo json_encode(['support'=>DB::table('whatsapp_support_cases')->where('contact_id',7)->get(['id','status','subject','created_at','resolved_at','updated_at','internal_notes'])]).PHP_EOL;
echo json_encode(['events'=>DB::table('whatsapp_messages')->where('id',562)->get(['metadata','content'])]).PHP_EOL;
foreach(new SplFileObject('storage/logs/laravel.log') as $line){if(preg_match('/^\[2026-09-25 (15:53:|18:58:)/',$line))echo substr($line,0,1100);}
