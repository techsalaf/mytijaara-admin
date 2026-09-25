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
        $query = WhatsAppConversation::with(['contact', 'vendor', 'onboardingSession', 'messages' => function ($q) {
            $q->latest();
        }]);

        // Filters
        $filter = $request->input('filter', 'all');
        $search = $request->input('search');

        if ($filter === 'unread') {
            $query->whereHas('messages', function($q) {
                $q->where('direction', 'inbound')->whereNull('read_at');
            });
        } elseif ($filter === 'ai') {
            $query->where('state', 'ai_active');
        } elseif ($filter === 'human') {
            $query->where('state', 'human_handoff');
        } elseif ($filter === 'stuck') {
            $query->where('state', 'onboarding_paused');
        } elseif ($filter === 'incomplete') {
            $query->where('state', 'onboarding_active');
        } elseif ($filter === 'completed') {
            $query->where('state', 'onboarding_completed');
        }

        if ($search) {
            $query->whereHas('contact', function($q) use ($search) {
                $q->where('phone_number', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            })->orWhereHas('vendor', function($q) use ($search) {
                $q->where('f_name', 'like', "%{$search}%")
                  ->orWhere('l_name', 'like', "%{$search}%");
            });
        }

        $conversations = $query->orderBy('last_activity_at', 'desc')->paginate(20);

        return view('whatsappvendorconcierge::admin.inbox.index', compact('conversations', 'filter', 'search'));
    }

    public function show(Request $request, $id)
    {
        $query = WhatsAppConversation::with(['contact', 'vendor', 'onboardingSession', 'messages' => function ($q) {
            $q->latest();
        }]);

        $filter = $request->input('filter', 'all');
        $search = $request->input('search');

        if ($filter === 'unread') {
            $query->whereHas('messages', function($q) {
                $q->where('direction', 'inbound')->whereNull('read_at');
            });
        } elseif ($filter === 'ai') {
            $query->where('state', 'ai_active');
        } elseif ($filter === 'human') {
            $query->where('state', 'human_handoff');
        } elseif ($filter === 'stuck') {
            $query->where('state', 'onboarding_paused');
        } elseif ($filter === 'incomplete') {
            $query->where('state', 'onboarding_active');
        } elseif ($filter === 'completed') {
            $query->where('state', 'onboarding_completed');
        }

        if ($search) {
            $query->whereHas('contact', function($q) use ($search) {
                $q->where('phone_number', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            })->orWhereHas('vendor', function($q) use ($search) {
                $q->where('f_name', 'like', "%{$search}%")
                  ->orWhere('l_name', 'like', "%{$search}%");
            });
        }

        $conversations = $query->orderBy('last_activity_at', 'desc')->paginate(20);

        $conversation = WhatsAppConversation::with(['contact', 'vendor', 'onboardingSession'])->findOrFail($id);
        $messages = $conversation->messages()->orderBy('created_at', 'asc')->get();
        
        // mark unread as read
        $conversation->messages()->where('direction', 'inbound')->whereNull('read_at')->update(['read_at' => now()]);

        return view('whatsappvendorconcierge::admin.inbox.show', compact('conversations', 'conversation', 'messages', 'filter', 'search'));
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
            
            $msg = WhatsAppMessage::logOutbound($conversation->id, [
                'type' => 'text',
                'text' => ['body' => $request->message]
            ], $response);
            
            $metadata = $msg->metadata ?? [];
            $metadata['origin'] = 'human_operator';
            $msg->update(['metadata' => $metadata]);

            return response()->json([
                'success' => true,
                'message' => [
                    'id' => $msg->id,
                    'text' => $request->message,
                    'created_at' => $msg->created_at->format('H:i'),
                    'status' => $msg->status
                ]
            ]);
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
            if (!$support->getActiveCase($conversation->contact)) {
                $support->createCase($conversation->contact, "Manual takeover from Inbox", 'general', 'medium', null, $conversation);
            }
        } else {
            if ($conversation->onboarding_session_id && !$conversation->vendor_id) {
                $conversation->transitionTo('onboarding_active');
            } else {
                $conversation->transitionTo('ai_active');
            }

            if ($case = $support->getActiveCase($conversation->contact)) {
                $support->resolveCase($case, "Handed back to AI via Inbox UI");
            }
        }

        Toastr::success("Conversation state changed to " . str_replace('_', ' ', strtoupper($newState)));
        return back();
    }
}
