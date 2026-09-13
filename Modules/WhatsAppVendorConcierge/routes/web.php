<?php

use Illuminate\Support\Facades\Route;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Api\WebhookController;

/*
|--------------------------------------------------------------------------
| WhatsApp Webhook Routes
|--------------------------------------------------------------------------
|
| Public routes for Meta WhatsApp Cloud API webhook verification and events.
| No authentication required - Meta validates via verify token and signature.
|
*/

Route::prefix(config('whatsapp-vendor-concierge.webhook.path', 'webhooks/whatsapp'))->group(function () {
    // GET: Webhook verification (Meta calls this during setup)
    Route::get('/', [WebhookController::class, 'verify'])
        ->name('whatsapp.webhook.verify');

    // POST: Incoming messages, status updates, flow responses
    Route::post('/', [WebhookController::class, 'handle'])
        ->name('whatsapp.webhook.handle');
});

// Health check endpoint
Route::get('webhooks/whatsapp/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'WhatsApp Vendor Concierge',
        'timestamp' => now()->toISOString(),
    ]);
})->name('whatsapp.webhook.health');

/*
|--------------------------------------------------------------------------
| Secure Credential Creation (HTTPS Web Flow)
|--------------------------------------------------------------------------
|
| Mobile-first secure password setup page for WhatsApp vendor onboarding.
| Zero plaintext passwords are ever sent or logged in WhatsApp.
|
*/
Route::middleware(['web'])->group(function () {
    Route::get('/whatsapp/onboarding/password/{token}', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web\SecurePasswordController::class, 'show'])
        ->name('whatsapp.onboarding.password');

    Route::post('/whatsapp/onboarding/password/{token}', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web\SecurePasswordController::class, 'store'])
        ->name('whatsapp.onboarding.password.store')
        ->middleware('throttle:10,1');
});