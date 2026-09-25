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

        [$contact, , $conv] = $this->application();
        $conv->state = 'human_handoff';
        $conv->updated_at = now()->subHours(5);
        $conv->save();

        $diag = $diagnosticService->diagnoseConversation($conv);

        $this->assertEquals('stale_human_handoff', $diag['failure_category']);
        $this->assertEquals('release_stale_handoff', $diag['recommended_action']);
        $this->assertEquals('safe_manual', $diag['safety_classification']);
        $this->assertEquals('human_agent', $diag['expected_next_responder']);
    }

    public function test_diagnose_normal_conversation_correctly()
    {
        $diagnosticService = new ConciergeDiagnosticService();

        [$contact, , $conv] = $this->application();

        $diag = $diagnosticService->diagnoseConversation($conv);

        $this->assertContains($diag['safety_classification'], ['none', 'safe_manual', 'safe_auto']);
        $this->assertNotEmpty($diag['correlation_id']);
    }

    public function test_recovery_dry_run_does_not_mutate_state()
    {
        [$contact, , $conv] = $this->application();
        $conv->state = 'human_handoff';
        $conv->updated_at = now()->subHours(5);
        $conv->save();
        $recovery = app(ConciergeRecoveryService::class);

        $result = $recovery->releaseStaleHandoff($conv, dryRun: true);

        $this->assertEquals('dry_run_passed', $result['status']);
        $this->assertEquals('human_handoff', $conv->state); // Must not mutate on dry-run!
        $this->assertEquals('onboarding_active', $result['proposed_state']);
    }
}
