<?php
require_once __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingEvent;

// 1. Let's look at recent conversations
$convs = WhatsAppConversation::orderBy('last_activity_at', 'desc')->limit(15)->get();

$report = [];
foreach ($convs as $conv) {
    $contact = $conv->contact;
    $lastIn = WhatsAppMessage::where('conversation_id', $conv->id)->where('direction', 'inbound')->orderBy('id', 'desc')->first();
    $lastOut = WhatsAppMessage::where('conversation_id', $conv->id)->where('direction', 'outbound')->orderBy('id', 'desc')->first();
    
    $events = OnboardingEvent::where('onboarding_session_id', $conv->onboarding_session_id)->orderBy('id', 'desc')->limit(3)->get();
    
    $report[] = [
        'conv_id' => $conv->id,
        'phone' => $contact->phone_number,
        'name' => $contact->name,
        'state' => $conv->state,
        'step' => $conv->current_step,
        'last_in' => $lastIn ? ['time' => $lastIn->created_at->toIso8601String(), 'text' => $lastIn->raw_text, 'type' => $lastIn->type] : null,
        'last_out' => $lastOut ? ['time' => $lastOut->created_at->toIso8601String(), 'text' => $lastOut->raw_text, 'type' => $lastOut->type] : null,
        'recent_events' => $events->map(fn($e) => ['event' => $e->event_type, 'step' => $e->step, 'payload' => $e->payload]),
    ];
}

echo json_encode($report, JSON_PRETTY_PRINT);
