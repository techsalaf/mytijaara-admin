<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingEvent extends Model
{
    protected $table = 'onboarding_events';

    protected $fillable = [
        'onboarding_session_id',
        'contact_id',
        'event_type',
        'step',
        'payload',
        'metadata',
        'duration_ms',
    ];

    protected $casts = [
        'payload' => 'array',
        'metadata' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(OnboardingSession::class, 'onboarding_session_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'contact_id');
    }

    /**
     * Log an onboarding event.
     */
    public static function log(
        int $sessionId,
        int $contactId,
        string $eventType,
        ?string $step = null,
        array $payload = [],
        array $metadata = [],
        ?int $durationMs = null
    ): self {
        return self::create([
            'onboarding_session_id' => $sessionId,
            'contact_id' => $contactId,
            'event_type' => $eventType,
            'step' => $step,
            'payload' => $payload,
            'metadata' => $metadata,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Predefined event types for analytics.
     */
    public const EVENT_TYPES = [
        'onboarding_started',
        'business_name_submitted',
        'category_selected',
        'location_shared',
        'contact_submitted',
        'documents_uploaded',
        'review_started',
        'application_submitted',
        'application_approved',
        'application_rejected',
        'onboarding_abandoned',
        'step_completed',
        'step_failed',
        'ai_interaction',
        'human_handoff',
        'verification_requested',
        'verification_completed',
        'flow_response_received',
    ];
}