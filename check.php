<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo 'Jobs: ' . \DB::table('jobs')->count() . PHP_EOL;
echo 'Failed Jobs: ' . \DB::table('failed_jobs')->count() . PHP_EOL;
