<?php
require_once __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

var_dump(App\Models\Store::latest('id')->limit(5)->get(['id', 'name', 'vendor_id', 'status'])->toArray());
