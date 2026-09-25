<?php
require_once __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$c = \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation::find(5);
if ($c) {
    $c->update(['state' => 'ai_active']);
    echo "Reset conversation 5 to ai_active\n";
    
    // Close the support case if it exists
    $support = app(\Modules\WhatsAppVendorConcierge\app\Services\SupportCaseService::class);
    $case = $support->getActiveCase($c->contact);
    if ($case) {
        $case->update(['status' => 'resolved', 'resolved_at' => now()]);
        echo "Closed support case\n";
    }
}
