<?php

namespace Modules\WhatsAppVendorConcierge\app\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    protected $namespace = null;

    public function boot(): void
    {
        parent::boot();
    }

    public function map(): void
    {
        // Routes are registered directly in WhatsAppVendorConciergeServiceProvider via loadRoutesFrom
    }
}