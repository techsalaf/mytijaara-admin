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
    private function conversations(Request $request)
    {
        $query = WhatsAppConversation::with(['contact', 'vendor.stores', 'onboardingSession', 'messages'=>fn ($q)=>$q->latest('created_at')->latest('id')->limit(1)]);
        $filter = $request->input('filter', 'all');
        if ($filter === 'unread') $query->whereHas('messages', fn ($q)=>$q->where('direction','inbound')->whereNull('read_at'));
        elseif ($filter === 'stuck') {
            $ids = app(\Modules\WhatsAppVendorConcierge\app\Services\Operations\ConciergeDiagnosticService::class)->scan('stuck')->pluck('conversation.id');
            $query->whereIn('id',$ids);
        } elseif (isset(['ai'=>'ai_active','human'=>'human_handoff','incomplete'=>'onboarding_active','completed'=>'onboarding_completed'][$filter])) {
            $query->where('state',['ai'=>'ai_active','human'=>'human_handoff','incomplete'=>'onboarding_active','completed'=>'onboarding_completed'][$filter]);
        }
        if ($search = $request->input('search')) {
            $query->where(function ($group) use ($search) {
                $group->whereHas('contact', fn ($q)=>$q->where('phone_number','like',"%{$search}%")->orWhere('display_name','like',"%{$search}%"))
                    ->orWhereHas('vendor', fn ($q)=>$q->where('f_name','like',"%{$search}%")->orWhere('l_name','like',"%{$search}%"));
            });
        }
        return $query->latest('last_activity_at')->paginate(20)->withQueryString();
    }

    public function index(Request $request)
    {
        $filter=$request->input('filter','all'); $search=$request->input('search');
        $conversations=$this->conversations($request);
        return view('whatsappvendorconcierge::admin.inbox.index',compact('conversations','filter','search'));
    }

    public function show(Request $request, $id)
    {
        $filter=$request->input('filter','all'); $search=$request->input('search');
        $conversations=$this->conversations($request);
        $conversation=WhatsAppConversation::with(['contact','vendor.stores','onboardingSession'])->findOrFail($id);
        $messages=$conversation->messages()->oldest('created_at')->oldest('id')->get();
        $diag=app(\Modules\WhatsAppVendorConcierge\app\Services\Operations\ConciergeDiagnosticService::class)->diagnoseConversation($conversation);
        $conversation->messages()->where('direction','inbound')->whereNull('read_at')->update(['read_at'=>now()]);
        return view('whatsappvendorconcierge::admin.inbox.show',compact('conversations','conversation','messages','filter','search','diag'));
    }

    public function sendMessage(Request $request, $id, WhatsAppGateway $gateway)
    {
        $request->validate([
            'message' => 'required|string|max:4096',
        ]);

        $conversation = WhatsAppConversation::findOrFail($id);
        $contact = $conversation->contact;
        abort_if(!$contact || $contact->is_blocked, 422, 'Messaging is disabled for this contact.');
        $lastInbound = $conversation->messages()->where('direction','inbound')->latest('created_at')->first();
        abort_unless($lastInbound && $lastInbound->created_at->gt(now()->subHours(24)), 422, 'The 24-hour reply window has closed. Use an approved resume template.');
        abort_unless($conversation->state === 'human_handoff', 409, 'Take over this conversation before sending a human reply.');


        try {
            $response = $gateway->sendTextMessage($contact->phone_number, $request->message);
            
            if (empty($response['messages'][0]['id'])) return response()->json(['success'=>false,'message'=>'WhatsApp rejected the message. Check delivery diagnostics.'], 502);
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
            return response()->json(['success' => false, 'message' => 'Unable to send the reply. Check delivery diagnostics.'], 500);
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
            if ($conversation->onboardingSession?->canResume() && !$conversation->contact?->vendor_id) {
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
