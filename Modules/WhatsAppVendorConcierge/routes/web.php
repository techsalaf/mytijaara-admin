<?php

use Illuminate\Support\Facades\Route;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiDashboardController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiDiagnosticsController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiInboxController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiProviderController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiRoutingDashboardController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\FlowControlController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\OperationsCenterController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\ProductDraftController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\ResumeCampaignController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\StoreDeliveryPolicyController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\StuckApplicationController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Api\FlowEndpointController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Api\WebhookController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web\FlowPasswordController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web\KycDocumentController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web\PolicyDocumentController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web\SecurePasswordController;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web\SubscriptionPaymentController;
use Modules\WhatsAppVendorConcierge\app\Http\Middleware\AuthorizeWhatsAppOperations;
use Modules\WhatsAppVendorConcierge\app\Http\Middleware\SecureCredentialPage;

Route::get('/registration-policy-documents/{version}', PolicyDocumentController::class)
    ->where('version', '[a-zA-Z0-9_-]+')
    ->middleware('throttle:120,1')
    ->name('whatsapp.policy-document');

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

Route::get('/admin/whatsapp/kyc/{media}', [KycDocumentController::class, 'show'])
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
Route::middleware(['web', SecureCredentialPage::class])->group(function () {
    Route::get('/whatsapp/onboarding/password/{token}', [SecurePasswordController::class, 'show'])
        ->middleware('throttle:20,1')
        ->name('whatsapp.onboarding.password');

    Route::post('/whatsapp/onboarding/password/{token}', [SecurePasswordController::class, 'store'])
        ->name('whatsapp.onboarding.password.store')
        ->middleware('throttle:10,1');
});

Route::get('/whatsapp/onboarding/subscription-payment/{session}', SubscriptionPaymentController::class)
    ->middleware(['web', 'signed', SecureCredentialPage::class, 'throttle:20,1'])
    ->name('whatsapp.onboarding.subscription-payment');

/*
|--------------------------------------------------------------------------
| Admin UI Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['web', 'admin'])->group(function () {
    Route::middleware([AuthorizeWhatsAppOperations::class, SecureCredentialPage::class])->prefix('admin/whatsapp/flows')->name('admin.whatsapp.flows.')->group(function () {
        $c = FlowControlController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::post('/operations', [$c, 'action'])->middleware('throttle:20,1')->name('action');
        Route::post('/deprecate', [$c, 'deprecate'])->middleware('throttle:10,1')->name('deprecate');
        Route::post('/emergency-disable', [$c, 'emergency'])->name('emergency');
        Route::put('/settings', [$c, 'settings'])->name('settings');
        Route::put('/limits', [$c, 'limits'])->name('limits');
        Route::put('/permissions', [$c, 'permissions'])->name('permissions');
        Route::get('/support-summary', [$c, 'report'])->name('report');
        Route::post('/policy-integrity', [$c, 'policyIntegrity'])->name('policy-integrity');
        Route::post('/policies', [$c, 'publishPolicy'])->name('policy-publish');
        Route::post('/policies/select', [$c, 'selectPolicy'])->name('policy-select');
        Route::post('/test-check', [$c, 'testCheck'])->middleware('throttle:10,1')->name('test-check');
        Route::post('/test-send', [$c, 'testSend'])->middleware('throttle:3,1')->name('test-send');
        Route::get('/applications', [$c, 'applications'])->name('applications');
        Route::get('/applications/{id}', [$c, 'application'])->whereNumber('id')->name('application');
        Route::post('/applications/{id}/recovery', [$c, 'recover'])->whereNumber('id')->name('recover');
        Route::post('/applications/{id}/registration-recovery', [$c, 'registrationRecovery'])->whereNumber('id')->middleware('throttle:3,1')->name('registration-recovery');
        Route::post('/presentation', [$c, 'presentation'])->name('presentation');
        Route::post('/select-revision', [$c, 'selectRevision'])->name('select-revision');
        Route::post('/operations/{id}/retry', [$c, 'retryOperation'])->whereUuid('id')->name('retry-operation');
    });
    Route::group(['prefix' => 'admin/whatsapp/ai-dashboard', 'as' => 'admin.whatsapp.ai-dashboard.'], function () {
        Route::get('/', [AiDashboardController::class, 'index'])->name('index');
    });

    Route::group(['prefix' => 'admin/whatsapp/ai-providers', 'as' => 'admin.whatsapp.ai-providers.'], function () {
        Route::get('/', [AiProviderController::class, 'index'])->name('index');
        Route::get('/create', [AiProviderController::class, 'create'])->name('create');
        Route::post('/store', [AiProviderController::class, 'store'])->name('store');
        Route::get('/edit/{aiProvider}', [AiProviderController::class, 'edit'])->name('edit');
        Route::put('/update/{aiProvider}', [AiProviderController::class, 'update'])->name('update');
        Route::delete('/destroy/{aiProvider}', [AiProviderController::class, 'destroy'])->name('destroy');
        Route::post('/toggle/{aiProvider}', [AiProviderController::class, 'toggle'])->name('toggle');
        Route::post('/test/{aiProvider}', [AiProviderController::class, 'test'])->name('test');

        // Diagnostic endpoints
        Route::post('/diagnostics/{aiProvider}/credentials', [AiDiagnosticsController::class, 'testCredentials'])->name('diagnostics.credentials');
        Route::post('/diagnostics/{aiProvider}/discovery', [AiDiagnosticsController::class, 'testDiscovery'])->name('diagnostics.discovery');
        Route::post('/diagnostics/{aiProvider}/inference', [AiDiagnosticsController::class, 'testInference'])->name('diagnostics.inference');
        Route::post('/diagnostics/{aiProvider}/bulk-test', [AiDiagnosticsController::class, 'bulkTest'])->name('diagnostics.bulk-test');
        Route::post('/sync-models/{aiProvider}', [AiProviderController::class, 'syncModels'])->name('sync-models');
        Route::post('/models/{model}/toggle', [AiProviderController::class, 'toggleModel'])->name('models.toggle');
        Route::post('/models/{model}/update', [AiProviderController::class, 'updateModel'])->name('models.update');
    });

    Route::group(['prefix' => 'admin/whatsapp/ai-routing', 'as' => 'admin.whatsapp.ai-routing.'], function () {
        Route::get('/', [AiRoutingDashboardController::class, 'index'])->name('index');
        Route::put('/policy/{policy}', [AiRoutingDashboardController::class, 'updatePolicy'])->name('policy.update');
        Route::post('/simulate', [AiRoutingDashboardController::class, 'simulate'])->name('simulate');
    });

    Route::group(['middleware' => [AuthorizeWhatsAppOperations::class], 'prefix' => 'admin/whatsapp/inbox', 'as' => 'admin.whatsapp.inbox.'], function () {
        Route::get('/', [AiInboxController::class, 'index'])->name('index');
        Route::get('/{conversation}', [AiInboxController::class, 'show'])->name('show');
        Route::post('/{conversation}/send', [AiInboxController::class, 'sendMessage'])->name('send');
        Route::post('/{conversation}/toggle-state', [AiInboxController::class, 'toggleState'])->name('toggle-state');
    });

    Route::group(['middleware' => [AuthorizeWhatsAppOperations::class], 'prefix' => 'admin/whatsapp/stuck-applications', 'as' => 'admin.whatsapp.stuck-applications.'], function () {
        Route::get('/', [StuckApplicationController::class, 'index'])->name('index');
        Route::post('/{id}/recover', [StuckApplicationController::class, 'recoveryAction'])->name('recover');
    });

    Route::group(['prefix' => 'admin/whatsapp/resume-campaigns', 'as' => 'admin.whatsapp.resume-campaigns.'], function () {
        Route::get('/', [ResumeCampaignController::class, 'index'])->name('index');
        Route::get('/create', [ResumeCampaignController::class, 'create'])->name('create');
        Route::post('/', [ResumeCampaignController::class, 'store'])->name('store');
        Route::post('/{campaign}/launch', [ResumeCampaignController::class, 'launch'])->name('launch');
    });

    Route::group(['middleware' => [AuthorizeWhatsAppOperations::class], 'prefix' => 'admin/whatsapp/operations-center', 'as' => 'admin.whatsapp.operations-center.'], function () {
        Route::get('/', [OperationsCenterController::class, 'index'])->name('index');
        Route::get('/conversation/{id}', [OperationsCenterController::class, 'show'])->name('show');
        Route::post('/conversation/{id}/preview', [OperationsCenterController::class, 'previewAction'])->name('preview');
        Route::post('/conversation/{id}/execute', [OperationsCenterController::class, 'executeAction'])->name('execute');
        Route::post('/bulk/preview', [OperationsCenterController::class, 'bulkPreview'])->name('bulk.preview');
        Route::post('/bulk/execute', [OperationsCenterController::class, 'bulkExecute'])->name('bulk.execute');
        Route::post('/health-check', [OperationsCenterController::class, 'runHealthCheck'])->name('health-check');
        Route::get('/audits', [OperationsCenterController::class, 'audits'])->name('audits');
    });
});

Route::middleware(['web', 'admin', AuthorizeWhatsAppOperations::class])->group(function () {
    Route::get('/admin/whatsapp/store-managed-delivery', [StoreDeliveryPolicyController::class, 'index'])->name('admin.whatsapp.delivery-policy.index');
    Route::post('/admin/whatsapp/store-managed-delivery', [StoreDeliveryPolicyController::class, 'update'])->name('admin.whatsapp.delivery-policy.update');
});

Route::middleware(['web', 'admin', AuthorizeWhatsAppOperations::class])->prefix('admin/whatsapp/product-drafts')->name('admin.whatsapp.product-drafts.')->group(function () {
    $controller = ProductDraftController::class;
    Route::get('/', [$controller, 'index'])->name('index');
    Route::get('/{draft}', [$controller, 'show'])->name('show');
    Route::get('/{draft}/image', [$controller, 'image'])->name('image');
    Route::post('/{draft}', [$controller, 'action'])->name('action');
});

Route::post('/webhooks/whatsapp/flow-data', FlowEndpointController::class)->middleware('throttle:120,1')->name('whatsapp.flow.exchange');
Route::middleware(['web', SecureCredentialPage::class, 'throttle:10,1'])->group(function () {
    Route::get('/whatsapp/flow/password', [FlowPasswordController::class, 'show'])->name('whatsapp.flow.password');
    Route::post('/whatsapp/flow/password', [FlowPasswordController::class, 'store'])->name('whatsapp.flow.password.store');
});
