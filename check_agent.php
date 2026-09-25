<?php
require_once __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$ref = new ReflectionClass('Laravel\Ai\Contracts\Agent');
foreach ($ref->getMethods() as $method) {
    echo $method->getName() . "\n";
}
