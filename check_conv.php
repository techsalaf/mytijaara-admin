<?php
require_once __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$c = \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation::with('contact')->orderBy('updated_at', 'desc')->first();
if ($c) {
    echo "State: " . $c->state . "\n";
    echo "Vendor ID: " . $c->contact->vendor_id . "\n";
}
