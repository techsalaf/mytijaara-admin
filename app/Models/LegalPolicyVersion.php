<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegalPolicyVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['effective_at' => 'immutable_datetime', 'retired_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Published policy records are immutable.'));
        static::deleting(fn () => throw new \LogicException('Published policy records cannot be deleted.'));
    }
}
