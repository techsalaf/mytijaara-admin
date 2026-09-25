<?php

namespace Modules\WhatsAppVendorConcierge\tests\Unit;

use Tests\TestCase;
use Mockery;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Services\Operations\ConciergeDiagnosticService;
use Modules\WhatsAppVendorConcierge\app\Services\Operations\ConciergeRecoveryService;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;
use Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService;
use Modules\WhatsAppVendorConcierge\app\Services\SupportCaseService;

use Modules\WhatsAppVendorConcierge\tests\Hardening\ApplicationFixtureTestCase;

class ConciergeOperationsTest extends ApplicationFixtureTestCase
{
    public function test_diagnose_stale_human_handoff_correctly()
    {
        $diagnosticService = new ConciergeDiagnosticService();

        $contact = new WhatsAppContact(['id' => 1, 'phone_number' => '2348011112222', 'name' => 'Test Vendor']);
        $conv = new WhatsAppConversation([
            'id' => 101,
            'contact_id' => 1,
            'state' => 'human_handoff',
            'current_step' => 'business_basics',
            'updated_at' => now()->subHours(5),
            'last_activity_at' => now()->subHours(5),
        ]);
        $conv->setRelation('contact', $contact);

        $diag = $diagnosticService->diagnoseConversation($conv);

        $this->assertEquals('stale_human_handoff', $diag['failure_category']);
        $this->assertEquals('release_stale_handoff', $diag['recommended_action']);
        $this->assertEquals('safe_manual', $diag['safety_classification']);
        $this->assertEquals('human_agent', $diag['expected_next_responder']);
    }

    public function test_diagnose_normal_conversation_correctly()
    {
        $diagnosticService = new ConciergeDiagnosticService();

        $contact = new WhatsAppContact(['id' => 2, 'phone_number' => '2348022223333', 'name' => 'Active Vendor']);
        $conv = new WhatsAppConversation([
            'id' => 102,
            'contact_id' => 2,
            'state' => 'onboarding_active',
            'current_step' => 'location',
            'last_activity_at' => now()->subMinutes(5),
        ]);
        $conv->setRelation('contact', $contact);

        $diag = $diagnosticService->diagnoseConversation($conv);

        $this->assertContains($diag['safety_classification'], ['none', 'safe_manual', 'safe_auto']);
        $this->assertNotEmpty($diag['correlation_id']);
    }

    public function test_recovery_dry_run_does_not_mutate_state()
    {
        $diagService = Mockery::mock(ConciergeDiagnosticService::class);
        $gateway = Mockery::mock(WhatsAppGateway::class);
        $onboarding = Mockery::mock(VendorOnboardingService::class);
        $support = Mockery::mock(SupportCaseService::class);

        $recovery = new ConciergeRecoveryService($diagService, $gateway, $onboarding, $support);

        $contact = new WhatsAppContact(['id' => 3, 'phone_number' => '2348033334444']);
        $conv = new WhatsAppConversation([
            'id' => 103,
            'contact_id' => 3,
            'state' => 'human_handoff',
            'onboarding_session_id' => 99,
        ]);
        $conv->setRelation('contact', $contact);

        $result = $recovery->releaseStaleHandoff($conv, dryRun: true);

        $this->assertEquals('dry_run_passed', $result['status']);
        $this->assertEquals('human_handoff', $conv->state); // Must not mutate on dry-run!
        $this->assertEquals('onboarding_active', $result['proposed_state']);
    }
}
