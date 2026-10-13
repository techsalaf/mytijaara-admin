<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Jobs\ProcessIncomingWhatsAppMessage;

// Inspect Conv 7 before
$conv7 = WhatsAppConversation::with(['contact', 'onboardingSession'])->find(7);
echo "Conv 7 state: " . $conv7->state . " | step: " . $conv7->current_step . PHP_EOL;

// Fetch message 1053
$msg1053 = WhatsAppMessage::find(1053);
if ($msg1053) {
    echo "Message 1053 found. Reprocessing..." . PHP_EOL;
    $rawPayload = [
        'id' => $msg1053->whatsapp_message_id,
        'from' => $conv7->contact->whatsapp_id,
        'timestamp' => $msg1053->created_at->timestamp,
        'type' => 'text',
        'text' => ['body' => $msg1053->raw_text],
    ];
    $metaContext = [
        'contacts' => [['profile' => ['name' => $conv7->contact->name], 'wa_id' => $conv7->contact->whatsapp_id]],
        'metadata' => ['phone_number_id' => config('whatsapp-vendor-concierge.api.phone_number_id')],
    ];
    try {
        // Execute the job logic directly
        $job = new ProcessIncomingWhatsAppMessage($rawPayload, $metaContext);
        app()->call([$job, 'handle']);
        echo "Reprocess job finished without error!" . PHP_EOL;
    } catch (\Throwable $e) {
        echo "Reprocess failed: " . $e->getMessage() . PHP_EOL;
        echo $e->getTraceAsString() . PHP_EOL;
    }
}

// Check latest outbound message for Conv 7
$latestOut = WhatsAppMessage::where('conversation_id', 7)->where('direction', 'outbound')->latest('id')->first();
if ($latestOut) {
    echo "Latest outbound for Conv 7: " . $latestOut->raw_text . PHP_EOL;
} else {
    echo "No outbound sent yet." . PHP_EOL;
}
