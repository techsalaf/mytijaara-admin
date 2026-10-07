<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorRegistrationConsent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['accepted_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Registration evidence is immutable.'));
        static::deleting(fn () => throw new \LogicException('Registration evidence cannot be deleted.'));
    }
}
