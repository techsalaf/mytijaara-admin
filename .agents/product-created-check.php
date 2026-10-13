<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo Illuminate\Support\Facades\DB::table('items')->where('id',2)->get(['id','store_id','module_id','name','status','is_approved','stock','price'])->toJson()."\n";
