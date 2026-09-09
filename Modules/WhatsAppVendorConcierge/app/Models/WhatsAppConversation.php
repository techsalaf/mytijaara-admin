<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppConversation extends Model
{
    protected $table = 'whatsapp_conversations';

    protected $fillable = [
        'contact_id',
        'vendor_id',
        'state',
        'current_intent',
        'current_step',
        'context',
        'collected_data',
        'onboarding_session_id',
        'last_activity_at',
        'expires_at',
    ];

    protected $casts = [
        'context' => 'array',
        'collected_data' => 'array',
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'contact_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'conversation_id');
    }

    public function onboardingSession(): BelongsTo
    {
        return $this->belongsTo(OnboardingSession::class, 'onboarding_session_id');
    }

    /**
     * Find or create active conversation for a contact.
     */
    public static function getOrCreateActive(int $contactId): self
    {
        $conversation = self::where('contact_id', $contactId)
            ->whereNotIn('state', ['closed', 'expired'])
            ->latest()
            ->first();

        if (!$conversation) {
            $conversation = self::create([
                'contact_id' => $contactId,
                'state' => 'new',
                'last_activity_at' => now(),
                'expires_at' => now()->addMinutes(config('whatsapp-vendor-concierge.onboarding.session_ttl_minutes', 10080)),
            ]);
        }

        return $conversation;
    }

    /**
     * Update conversation state and activity.
     */
    public function transitionTo(string $state, array $context = []): void
    {
        $this->update([
            'state' => $state,
            'context' => array_merge($this->context ?? [], $context),
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes(config('whatsapp-vendor-concierge.onboarding.max_inactive_minutes', 30)),
        ]);
    }

    /**
     * Check if conversation is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if conversation is in onboarding.
     */
    public function isOnboarding(): bool
    {
        return in_array($this->state, ['onboarding_active', 'onboarding_paused']);
    }

    /**
     * Check if conversation is active for AI.
     */
    public function isAiActive(): bool
    {
        return $this->state === 'ai_active';
    }
}