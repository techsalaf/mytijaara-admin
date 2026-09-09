<?php

namespace Modules\WhatsAppVendorConcierge\app\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * This namespace is applied to your controller routes.
     */
    protected $namespace = 'Modules\WhatsAppVendorConcierge\app\Http\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Define the routes for the application.
     */
    public function map(): void
    {
        $this->mapWebhookRoutes();
        $this->mapApiRoutes();
    }

    /**
     * Define the webhook routes (public, no auth).
     */
    protected function mapWebhookRoutes(): void
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(module_path('WhatsAppVendorConcierge', 'routes/web.php'));
    }

    /**
     * Define the API routes (authenticated).
     */
    protected function mapApiRoutes(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->namespace($this->namespace)
            ->group(module_path('WhatsAppVendorConcierge', 'routes/api.php'));
    }
}