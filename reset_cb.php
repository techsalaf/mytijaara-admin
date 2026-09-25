<?php
require_once __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

\Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection::query()->update([
    'status' => 'models_discovered',
    'consecutive_failures' => 0,
    'cooldown_until' => null,
]);
echo "Reset all connections circuit breakers.\n";
