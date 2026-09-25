<?php
require_once __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$cs = \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation::with('contact')->orderBy('updated_at', 'desc')->take(5)->get();
foreach ($cs as $c) {
    echo "ID: {$c->id}, State: {$c->state}, Vendor ID: {$c->contact->vendor_id}\n";
}
