<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;
use Modules\WhatsAppVendorConcierge\app\Services\SupportCaseService;
use Brian2694\Toastr\Facades\Toastr;

class AiInboxController extends Controller
{
    public function index(Request $request)
    {
        $conversations = WhatsAppConversation::with(['contact', 'vendor'])
            ->orderBy('last_activity_at', 'desc')
            ->paginate(20);

        return view('whatsappvendorconcierge::admin.inbox.index', compact('conversations'));
    }

    public function show($id)
    {
        $conversations = WhatsAppConversation::with(['contact', 'vendor'])
            ->orderBy('last_activity_at', 'desc')
            ->paginate(20);

        $conversation = WhatsAppConversation::with(['contact', 'vendor'])->findOrFail($id);
        $messages = $conversation->messages()->orderBy('created_at', 'asc')->get();

        return view('whatsappvendorconcierge::admin.inbox.show', compact('conversations', 'conversation', 'messages'));
    }

    public function sendMessage(Request $request, $id, WhatsAppGateway $gateway)
    {
        $request->validate([
            'message' => 'required|string',
        ]);

        $conversation = WhatsAppConversation::findOrFail($id);
        $contact = $conversation->contact;

        try {
            $response = $gateway->sendTextMessage($contact->phone_number, $request->message);
            WhatsAppMessage::logOutbound($conversation->id, [
                'type' => 'text',
                'text' => ['body' => $request->message]
            ], $response);

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function toggleState(Request $request, $id, SupportCaseService $support)
    {
        $request->validate([
            'state' => 'required|string|in:ai_active,human_handoff',
        ]);

        $conversation = WhatsAppConversation::with('contact')->findOrFail($id);
        $newState = $request->state;

        if ($newState === 'human_handoff') {
            $conversation->transitionTo('human_handoff');
            // Create a support case if one doesn't exist
            if (!$support->getActiveCase($conversation->contact)) {
                $support->createCase($conversation->contact, "Manual takeover from Inbox", 'general', 'medium', null, $conversation);
            }
        } else {
            // Restore to its proper state instead of blindly setting ai_active.
            // If they have an onboarding session, they should probably go back to onboarding_active.
            if ($conversation->onboarding_session_id && !$conversation->vendor_id) {
                $conversation->transitionTo('onboarding_active');
            } else {
                $conversation->transitionTo('ai_active');
            }

            // Close the active support case
            if ($case = $support->getActiveCase($conversation->contact)) {
                $support->resolveCase($case, "Handed back to AI via Inbox UI");
            }
        }

        Toastr::success("Conversation state changed to " . str_replace('_', ' ', strtoupper($newState)));
        return back();
    }
}
