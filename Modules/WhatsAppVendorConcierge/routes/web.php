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

Route::get('/admin/whatsapp/kyc/{media}', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web\KycDocumentController::class, 'show'])
    ->middleware(['web', 'admin', 'module:store'])
    ->name('whatsapp.kyc.show');

/*
|--------------------------------------------------------------------------
| Secure Credential Creation (HTTPS Web Flow)
|--------------------------------------------------------------------------
|
| Mobile-first secure password setup page for WhatsApp vendor onboarding.
| Zero plaintext passwords are ever sent or logged in WhatsApp.
|
*/
Route::middleware(['web', \Modules\WhatsAppVendorConcierge\app\Http\Middleware\SecureCredentialPage::class])->group(function () {
    Route::get('/whatsapp/onboarding/password/{token}', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web\SecurePasswordController::class, 'show'])
        ->middleware('throttle:20,1')
        ->name('whatsapp.onboarding.password');

    Route::post('/whatsapp/onboarding/password/{token}', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web\SecurePasswordController::class, 'store'])
        ->name('whatsapp.onboarding.password.store')
        ->middleware('throttle:10,1');
});

Route::get('/whatsapp/onboarding/subscription-payment/{session}', \Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web\SubscriptionPaymentController::class)
    ->middleware(['web', 'signed', \Modules\WhatsAppVendorConcierge\app\Http\Middleware\SecureCredentialPage::class, 'throttle:20,1'])
    ->name('whatsapp.onboarding.subscription-payment');

/*
|--------------------------------------------------------------------------
| Admin UI Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['web', 'admin'])->group(function () {
    Route::group(['prefix' => 'admin/whatsapp/ai-dashboard', 'as' => 'admin.whatsapp.ai-dashboard.'], function () {
        Route::get('/', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiDashboardController::class, 'index'])->name('index');
    });

    Route::group(['prefix' => 'admin/whatsapp/ai-providers', 'as' => 'admin.whatsapp.ai-providers.'], function () {
        Route::get('/', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiProviderController::class, 'index'])->name('index');
        Route::get('/create', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiProviderController::class, 'create'])->name('create');
        Route::post('/store', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiProviderController::class, 'store'])->name('store');
        Route::get('/edit/{aiProvider}', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiProviderController::class, 'edit'])->name('edit');
        Route::put('/update/{aiProvider}', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiProviderController::class, 'update'])->name('update');
        Route::delete('/destroy/{aiProvider}', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiProviderController::class, 'destroy'])->name('destroy');
        Route::post('/toggle/{aiProvider}', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiProviderController::class, 'toggle'])->name('toggle');
        Route::post('/test/{aiProvider}', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiProviderController::class, 'test'])->name('test');
        
        // Diagnostic endpoints
        Route::post('/diagnostics/{aiProvider}/credentials', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiDiagnosticsController::class, 'testCredentials'])->name('diagnostics.credentials');
        Route::post('/diagnostics/{aiProvider}/discovery', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiDiagnosticsController::class, 'testDiscovery'])->name('diagnostics.discovery');
        Route::post('/diagnostics/{aiProvider}/inference', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiDiagnosticsController::class, 'testInference'])->name('diagnostics.inference');
        Route::post('/sync-models/{aiProvider}', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiProviderController::class, 'syncModels'])->name('sync-models');
        Route::post('/models/{model}/toggle', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiProviderController::class, 'toggleModel'])->name('models.toggle');
        Route::post('/models/{model}/update', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiProviderController::class, 'updateModel'])->name('models.update');
    });

    Route::group(['prefix' => 'admin/whatsapp/ai-routing', 'as' => 'admin.whatsapp.ai-routing.'], function () {
        Route::get('/', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiRoutingDashboardController::class, 'index'])->name('index');
        Route::put('/policy/{policy}', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiRoutingDashboardController::class, 'updatePolicy'])->name('policy.update');
        Route::post('/simulate', [\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiRoutingDashboardController::class, 'simulate'])->name('simulate');
    });
});
