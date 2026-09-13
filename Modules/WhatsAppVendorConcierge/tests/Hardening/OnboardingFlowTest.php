<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;

class OnboardingFlowTest extends HardeningTestCase
{
    public function test_onboarding_has_explicit_subscription_and_rental_pickup_steps(): void
    {
        $steps = OnboardingSession::getSteps();

        $this->assertContains('subscription_package', $steps);
        $this->assertContains('pickup_zone_selection', $steps);
        $this->assertLessThan(
            array_search('terms_acceptance', $steps, true),
            array_search('subscription_package', $steps, true)
        );
    }
}
