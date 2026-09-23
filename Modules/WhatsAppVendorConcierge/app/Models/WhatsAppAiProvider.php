<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\WhatsAppVendorConcierge\Database\Factories\WhatsAppAiProviderFactory;

class WhatsAppAiProvider extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'driver',
        'base_url',
        'api_key',
        'model',
        'priority',
        'is_active',
        'status',
        'last_failed_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'api_key',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'last_failed_at' => 'datetime',
        'api_key' => 'encrypted',
    ];

    /**
     * Scope a query to only include active and working providers ordered by priority.
     */
    public function scopeActiveAndWorking($query)
    {
        return $query->where('is_active', true)
                     ->whereNotNull('api_key')
                     ->orderBy('priority', 'asc');
    }
}
