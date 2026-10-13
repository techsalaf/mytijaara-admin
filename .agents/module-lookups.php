<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach(['vehicle_categories','vehicle_brands','parcel_categories'] as $t){if(Illuminate\Support\Facades\Schema::hasTable($t))echo json_encode(['table'=>$t,'count'=>Illuminate\Support\Facades\DB::table($t)->count(),'columns'=>Illuminate\Support\Facades\Schema::getColumnListing($t)]).PHP_EOL;}
