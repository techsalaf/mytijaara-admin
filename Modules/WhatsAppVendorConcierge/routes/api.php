<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WhatsApp Vendor Concierge API Routes
|--------------------------------------------------------------------------
|
| Internal API endpoints (authenticated via Laravel Passport / session).
| Used by admin panel, vendor dashboard, and integrations.
| Controllers are scaffolded as needed - currently minimal for webhook-only operation.
|
*/

Route::middleware(['auth:api'])->group(function () {
    // Placeholder routes - controllers to be implemented as needed
    // Route::prefix('whatsapp')->group(function () {
    //     Route::post('/send', [WhatsAppMessageController::class, 'send'])->name('whatsapp.api.send');
    //     Route::get('/messages/{conversationId}', [WhatsAppMessageController::class, 'history'])->name('whatsapp.api.messages');
    //     Route::get('/conversations', [WhatsAppMessageController::class, 'conversations'])->name('whatsapp.api.conversations');
    // });
    //
    // Route::prefix('onboarding')->group(function () {
    //     Route::get('/sessions', [OnboardingSessionController::class, 'index'])->name('whatsapp.api.onboarding.sessions');
    //     Route::get('/sessions/{id}', [OnboardingSessionController::class, 'show'])->name('whatsapp.api.onboarding.show');
    //     Route::get('/analytics', [OnboardingSessionController::class, 'analytics'])->name('whatsapp.api.onboarding.analytics');
    // });
    //
    // Route::middleware(['role:admin'])->group(function () {
    //     Route::post('/webhooks/whatsapp/test-signature', [WhatsAppWebhookDebugController::class, 'testSignature'])->name('whatsapp.api.debug.signature');
    // });
});