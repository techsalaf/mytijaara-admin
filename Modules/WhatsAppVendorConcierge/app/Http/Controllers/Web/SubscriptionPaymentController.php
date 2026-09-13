<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Services\SubscriptionLifecycleService;

class SubscriptionPaymentController extends Controller
{
    public function __construct(
        protected SubscriptionLifecycleService $lifecycleService
    ) {}

    /**
     * Send a verified WhatsApp applicant into the existing subscription payment
     * flow. The signed route prevents guessed application IDs from becoming a
     * payment continuation link.
     */
    public function __invoke(OnboardingSession $session): RedirectResponse
    {
        abort_unless($session->status === 'submitted' && $session->store_id && $session->vendor_id, 404);

        $store = Store::whereKey($session->store_id)
            ->where('vendor_id', $session->vendor_id)
            ->whereNotNull('package_id')
            ->firstOrFail();

        abort_unless($store->store_business_model === 'none', 409);

        $this->lifecycleService->recordPending($session);

        return redirect()->route('restaurant.secondStep', [
            'store_id' => $store->id,
            'business_plan' => 'subscription-base',
        ]);
    }
}
