<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use App\Models\Vendor;
use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnboardingSession extends Model
{
    protected $table = 'onboarding_sessions';

    protected $fillable = [
        'contact_id',
        'vendor_id',
        'store_id',
        'flow_version',
        'status',
        'collected_data',
        'validation_errors',
        'current_step',
        'step_attempts',
        'started_at',
        'last_activity_at',
        'completed_at',
        'expires_at',
        'source',
        'attribution_code',
    ];

    protected $casts = [
        'collected_data' => 'array',
        'validation_errors' => 'array',
        'started_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'completed_at' => 'datetime',
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

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OnboardingEvent::class, 'onboarding_session_id');
    }

    /**
     * Get the ordered list of onboarding steps.
     */
    public static function getSteps(): array
    {
        return [
            'welcome',
            'business_basics',
            'owner_info',
            'category_selection',
            'location',
            'contact_info',
            'account_password',
            'store_branding',
            'business_plan',
            'kyc_documents',
            'terms_acceptance',
            'review_submit',
        ];
    }

    /**
     * Get next step.
     */
    public function getNextStep(): ?string
    {
        $steps = self::getSteps();
        $current = $this->current_step ?? 'welcome';

        // Backward compatibility for legacy step names
        if ($current === 'operating_hours') {
            return 'store_branding';
        }
        if ($current === 'documents') {
            return 'business_plan';
        }

        $currentIndex = array_search($current, $steps);

        if ($currentIndex === false || $currentIndex >= count($steps) - 1) {
            return null;
        }

        return $steps[$currentIndex + 1];
    }

    /**
     * Get previous step.
     */
    public function getPreviousStep(): ?string
    {
        $steps = self::getSteps();
        $currentIndex = array_search($this->current_step ?? 'welcome', $steps);

        if ($currentIndex <= 0) {
            return null;
        }

        return $steps[$currentIndex - 1];
    }

    /**
     * Advance to next step.
     */
    public function advanceStep(): bool
    {
        $nextStep = $this->getNextStep();

        if (!$nextStep) {
            return false;
        }

        $this->update([
            'current_step' => $nextStep,
            'step_attempts' => 0,
            'last_activity_at' => now(),
        ]);

        return true;
    }

    /**
     * Update collected data.
     */
    public function updateData(array $data): void
    {
        $this->update([
            'collected_data' => array_merge($this->collected_data ?? [], $data),
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Mark as submitted.
     */
    public function markSubmitted(): void
    {
        $this->update([
            'status' => 'submitted',
            'completed_at' => now(),
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Check if session is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if session can be resumed.
     */
    public function canResume(): bool
    {
        $validStatuses = array_merge(self::getSteps(), [
            'started',
            'onboarding_paused',
            'operating_hours',
            'documents',
            'store_branding',
            'business_plan',
            'kyc_documents',
            'terms_acceptance',
        ]);

        return in_array($this->status, $validStatuses)
            && !$this->isExpired();
    }
}