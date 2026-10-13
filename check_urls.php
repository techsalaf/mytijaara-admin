<?php
require_once __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$connections = \Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection::all();
foreach($connections as $c) {
    echo $c->name . " -> " . $c->getBaseUrl() . "\n";
}
