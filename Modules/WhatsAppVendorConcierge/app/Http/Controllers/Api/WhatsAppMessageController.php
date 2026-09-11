<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\WhatsAppVendorConcierge\app\Jobs\SendWhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;

class WhatsAppMessageController extends Controller
{
    /**
     * Send an outbound WhatsApp message.
     */
    public function send(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'recipient_phone' => 'required|string',
            'type' => 'required|string|in:text,template,interactive',
            'content' => 'required|array',
            'conversation_id' => 'nullable|integer|exists:whatsapp_conversations,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $recipientPhone = preg_replace('/[^0-9]/', '', (string) $request->input('recipient_phone'));
        $type = $request->input('type');
        $content = $request->input('content');
        $conversationId = $request->input('conversation_id');

        if (!$conversationId) {
            $contact = WhatsAppContact::where('phone_number', $recipientPhone)->first();
            if ($contact) {
                $conversation = WhatsAppConversation::getOrCreateActive($contact->id);
                $conversationId = $conversation->id;
            }
        }

        SendWhatsAppMessage::dispatch(
            $recipientPhone,
            $type,
            $content,
            $conversationId
        )->onQueue(config('whatsapp-vendor-concierge.queue.jobs.send_message', 'whatsapp.send_message'));

        return response()->json([
            'status' => 'queued',
            'message' => 'WhatsApp message dispatched to outbound queue',
            'recipient_phone' => $recipientPhone,
            'conversation_id' => $conversationId,
        ], 202);
    }

    /**
     * Get paginated message history for a conversation.
     */
    public function history(int $conversationId): JsonResponse
    {
        $conversation = WhatsAppConversation::with(['contact', 'vendor'])->findOrFail($conversationId);

        $messages = WhatsAppMessage::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc')
            ->paginate(50);

        return response()->json([
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    /**
     * List active WhatsApp conversations.
     */
    public function conversations(Request $request): JsonResponse
    {
        $query = WhatsAppConversation::with(['contact', 'vendor'])
            ->withCount('messages');

        if ($request->filled('state')) {
            $query->where('state', $request->input('state'));
        }

        if ($request->filled('search')) {
            $term = $request->input('search');
            $query->whereHas('contact', function ($q) use ($term) {
                $q->where('phone_number', 'like', "%{$term}%")
                    ->orWhere('display_name', 'like', "%{$term}%");
            });
        }

        $conversations = $query->orderBy('last_activity_at', 'desc')->orderBy('updated_at', 'desc')->paginate(20);

        return response()->json($conversations);
    }
}
