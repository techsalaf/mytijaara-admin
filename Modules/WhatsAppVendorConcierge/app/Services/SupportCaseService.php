<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppSupportCase;

class SupportCaseService
{
    /**
     * Create a new structured support case.
     */
    public function createCase(
        WhatsAppContact $contact,
        string $subject,
        string $category = 'general',
        string $priority = 'medium',
        ?string $initialMessage = null,
        ?WhatsAppConversation $conversation = null
    ): WhatsAppSupportCase {
        // Generate unique ticket ID: TCK-YYYYMM-XXXX
        $ticketId = 'TCK-' . Carbon::now()->format('Ym') . '-' . strtoupper(Str::random(4));

        $slaHours = match ($priority) {
            'urgent' => 2,
            'high' => 6,
            'low' => 48,
            default => 24,
        };

        $safeSummary = $initialMessage ? Str::limit(strip_tags($initialMessage), 300) : null;

        $case = WhatsAppSupportCase::create([
            'ticket_id' => $ticketId,
            'contact_id' => $contact->id,
            'vendor_id' => $contact->vendor_id,
            'store_id' => $contact->vendor?->stores?->first()?->id ?? $contact->vendor?->store?->id,
            'category' => $category,
            'priority' => $priority,
            'status' => 'open',
            'subject' => Str::limit($subject, 250),
            'safe_summary' => $safeSummary,
            'sla_expires_at' => Carbon::now()->addHours($slaHours),
        ]);

        // Transition conversation out of AI to human support if conversation is provided
        if ($conversation) {
            $conversation->state = 'human_handoff';
            $conversation->save();
        }

        Log::info('WhatsApp support case opened', [
            'ticket_id' => $ticketId,
            'contact_id' => $contact->id,
            'priority' => $priority,
            'category' => $category,
        ]);

        return $case;
    }

    /**
     * Get the current active open/in-progress case for a contact.
     */
    public function getActiveCase(WhatsAppContact $contact): ?WhatsAppSupportCase
    {
        return WhatsAppSupportCase::where('contact_id', $contact->id)
            ->whereIn('status', ['open', 'in_progress'])
            ->latest()
            ->first();
    }

    /**
     * Find case by ticket ID.
     */
    public function findByTicketId(string $ticketId): ?WhatsAppSupportCase
    {
        return WhatsAppSupportCase::where('ticket_id', strtoupper(trim($ticketId)))->first();
    }

    /**
     * Append a reply or customer update to an existing case.
     */
    public function appendCustomerMessage(WhatsAppSupportCase $case, string $message): WhatsAppSupportCase
    {
        $timestamp = Carbon::now()->format('Y-m-d H:i');
        $cleanMessage = Str::limit(strip_tags($message), 500);

        $notes = $case->internal_notes ? $case->internal_notes . "\n" : "";
        $case->internal_notes = $notes . "[{$timestamp} Vendor]: {$cleanMessage}";
        $case->save();

        return $case;
    }

    /**
     * Resolve a support case.
     */
    public function resolveCase(
        WhatsAppSupportCase $case,
        string $resolutionNotes,
        ?WhatsAppConversation $conversation = null
    ): WhatsAppSupportCase {
        $case->status = 'resolved';
        $case->resolved_at = Carbon::now();

        $timestamp = Carbon::now()->format('Y-m-d H:i');
        $notes = $case->internal_notes ? $case->internal_notes . "\n" : "";
        $case->internal_notes = $notes . "[{$timestamp} Resolution]: {$resolutionNotes}";
        $case->save();

        // If conversation is in human_handoff mode, return to active
        if ($conversation && $conversation->state === 'human_handoff') {
            $conversation->state = 'ai_active';
            $conversation->save();
        }

        Log::info('WhatsApp support case resolved', [
            'ticket_id' => $case->ticket_id,
        ]);

        return $case;
    }

    /**
     * Reopen a resolved case within the 48-hour window.
     */
    public function reopenCase(
        WhatsAppSupportCase $case,
        string $reason,
        ?WhatsAppConversation $conversation = null
    ): array {
        if ($case->status === 'closed') {
            return [
                'success' => false,
                'message' => 'This ticket is permanently closed. A new ticket must be opened.',
            ];
        }

        if ($case->status === 'resolved' && $case->resolved_at) {
            $hoursSinceResolution = Carbon::now()->diffInHours($case->resolved_at);
            if ($hoursSinceResolution > 48) {
                $case->status = 'closed';
                $case->closed_at = Carbon::now();
                $case->save();

                return [
                    'success' => false,
                    'message' => 'Ticket resolution is older than 48 hours and has been closed. Please open a new case.',
                ];
            }
        }

        $case->status = 'open';
        $case->resolved_at = null;
        $timestamp = Carbon::now()->format('Y-m-d H:i');
        $notes = $case->internal_notes ? $case->internal_notes . "\n" : "";
        $case->internal_notes = $notes . "[{$timestamp} Reopened]: {$reason}";
        $case->save();

        if ($conversation) {
            $conversation->state = 'human_handoff';
            $conversation->save();
        }

        Log::info('WhatsApp support case reopened', [
            'ticket_id' => $case->ticket_id,
            'reason' => $reason,
        ]);

        return [
            'success' => true,
            'message' => "Ticket #{$case->ticket_id} has been reopened. A support agent will assist you shortly.",
            'case' => $case,
        ];
    }
}
