<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Store;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;

echo "=== ACTIVE STORES 24-HOUR SERVICE WINDOW AUDIT ===\n";
$stores = Store::where('status', 1)->with('vendor')->get();

foreach ($stores as $s) {
    $phone = preg_replace('/[^0-9]/', '', $s->phone ?? $s->vendor?->phone);
    $conv = WhatsAppConversation::whereHas('contact', function ($q) use ($phone) {
        $q->where('phone_number', 'like', "%{$phone}%");
    })->latest('id')->first();
    
    $lastIn = $conv ? WhatsAppMessage::where('conversation_id', $conv->id)->where('direction', 'inbound')->latest('created_at')->first() : null;
    $windowOpen = $lastIn && $lastIn->created_at->gt(now()->subHours(24));
    $lastInTime = $lastIn ? $lastIn->created_at->toDateTimeString() : 'never';
    $windowStr = $windowOpen ? "OPEN (last: {$lastInTime})" : "CLOSED (last: {$lastInTime})";
    
    echo "Store: '{$s->name}' | Phone: {$phone} | Conv ID: " . ($conv?->id ?? 'none') . " | Service Window: {$windowStr}\n";
}
