<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WhatsAppFlow extends Model
{
    protected $table = 'whatsapp_flows';

    protected $fillable = [
        'flow_id',
        'name',
        'version',
        'schema',
        'screens',
        'status',
        'validation_rules',
        'published_at',
    ];

    protected $casts = [
        'schema' => 'array',
        'screens' => 'array',
        'validation_rules' => 'array',
        'published_at' => 'datetime',
    ];

    /**
     * Scope to only published flows.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /**
     * Scope to find flow by flow_id.
     */
    public function scopeByFlowId(Builder $query, string $flowId): Builder
    {
        return $query->where('flow_id', $flowId);
    }

    /**
     * Find the active published flow by name.
     */
    public static function findActiveByName(string $name): ?self
    {
        return static::where('name', $name)
            ->where('status', 'published')
            ->latest('version')
            ->first();
    }

    /**
     * Get specific screen schema by screen ID.
     */
    public function getScreen(string $screenId): ?array
    {
        if (empty($this->screens)) {
            return null;
        }

        foreach ($this->screens as $screen) {
            if (($screen['id'] ?? null) === $screenId) {
                return $screen;
            }
        }

        return null;
    }
}
