<?php

use Illuminate\Support\Facades\Route;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Api\WhatsAppMessageController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Api\OnboardingSessionController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Api\WhatsAppWebhookDebugController;

/*
|--------------------------------------------------------------------------
| WhatsApp Vendor Concierge API Routes
|--------------------------------------------------------------------------
|
| Internal API endpoints (authenticated via Laravel Passport or Admin session).
| Used by admin panel, vendor dashboard, and integrations.
|
*/

Route::prefix('api/v1/whatsapp')->middleware(['api'])->group(function () {
    Route::post('/send', [WhatsAppMessageController::class, 'send'])->name('whatsapp.api.send');
    Route::get('/messages/{conversationId}', [WhatsAppMessageController::class, 'history'])->name('whatsapp.api.messages');
    Route::get('/conversations', [WhatsAppMessageController::class, 'conversations'])->name('whatsapp.api.conversations');

    Route::prefix('onboarding')->group(function () {
        Route::get('/sessions', [OnboardingSessionController::class, 'index'])->name('whatsapp.api.onboarding.sessions');
        Route::get('/sessions/{id}', [OnboardingSessionController::class, 'show'])->name('whatsapp.api.onboarding.show');
        Route::get('/analytics', [OnboardingSessionController::class, 'analytics'])->name('whatsapp.api.onboarding.analytics');
    });

    Route::post('/test-signature', [WhatsAppWebhookDebugController::class, 'testSignature'])->name('whatsapp.api.debug.signature');
});