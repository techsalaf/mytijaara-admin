<?php
require_once __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$admin = App\Models\Admin::first();
auth('admin')->login($admin);
view()->share('errors', new Illuminate\Support\ViewErrorBag());

try {
    $c = Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation::with(['contact', 'vendor', 'onboardingSession', 'messages' => fn($q) => $q->latest()])->paginate(20);
    $html = view('whatsappvendorconcierge::admin.inbox.index', ['conversations' => $c, 'filter' => 'all', 'search' => null])->render();
    echo "SUCCESS: Rendered inbox index.blade.php cleanly (" . strlen($html) . " bytes)\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
}
