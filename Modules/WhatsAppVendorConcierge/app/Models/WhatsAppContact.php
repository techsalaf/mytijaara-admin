<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use App\Models\Vendor;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppContact extends Model
{
    protected $table = 'whatsapp_contacts';

    protected $fillable = [
        'whatsapp_id',
        'phone_number',
        'display_name',
        'profile_picture_url',
        'vendor_id',
        'user_id',
        'contact_type',
        'metadata',
        'first_interaction_at',
        'last_interaction_at',
        'opted_in_at',
        'is_blocked',
    ];

    protected $casts = [
        'metadata' => 'array',
        'first_interaction_at' => 'datetime',
        'last_interaction_at' => 'datetime',
        'opted_in_at' => 'datetime',
        'is_blocked' => 'boolean',
    ];

    /**
     * Get the vendor associated with this contact.
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Get the user (customer) associated with this contact.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get conversations for this contact.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(WhatsAppConversation::class, 'contact_id');
    }

    /**
     * Get the active conversation for this contact.
     */
    public function activeConversation(): HasMany
    {
        return $this->conversations()->whereNotIn('state', ['closed', 'expired'])->latest();
    }

    /**
     * Find or create contact by WhatsApp ID.
     */
    public static function findOrCreateByWhatsAppId(string $whatsappId, string $phoneNumber, array $profile = []): self
    {
        return self::updateOrCreate(
            ['whatsapp_id' => $whatsappId],
            [
                'phone_number' => $phoneNumber,
                'display_name' => $profile['name'] ?? null,
                'profile_picture_url' => $profile['profile_picture_url'] ?? null,
                'metadata' => $profile,
                'first_interaction_at' => self::where('whatsapp_id', $whatsappId)->exists() ? null : now(),
                'last_interaction_at' => now(),
            ]
        );
    }

    /**
     * Link contact to vendor.
     */
    public function linkToVendor(Vendor $vendor): void
    {
        $this->update([
            'vendor_id' => $vendor->id,
            'contact_type' => 'vendor',
        ]);
    }

    /**
     * Link contact to vendor applicant.
     */
    public function linkToApplicant(): void
    {
        $this->update([
            'contact_type' => 'vendor_applicant',
        ]);
    }

    /**
     * Check if contact is a registered vendor.
     */
    public function isVendor(): bool
    {
        return $this->contact_type === 'vendor' && $this->vendor_id !== null;
    }

    /**
     * Check if contact has pending application.
     */
    public function hasPendingApplication(): bool
    {
        return $this->contact_type === 'vendor_applicant';
    }
}