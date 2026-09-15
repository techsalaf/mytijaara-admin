<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use App\Models\Admin;
use App\Models\Store;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Modules\WhatsAppVendorConcierge\app\Jobs\RunVendorAiConversation;
use Modules\WhatsAppVendorConcierge\app\Jobs\SendVendorStatusNotification;
use Modules\WhatsAppVendorConcierge\app\Jobs\SendWhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\NotificationDelivery;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppSupportCase;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppVendorPreference;
use Modules\WhatsAppVendorConcierge\app\Services\AiBudgetService;
use Modules\WhatsAppVendorConcierge\app\Services\LanguagePreferenceService;
use Modules\WhatsAppVendorConcierge\app\Services\NotificationPreferenceService;
use Modules\WhatsAppVendorConcierge\app\Services\SupportCaseService;
use Tests\TestCase;

class PreferencesAndSupportTest extends ApplicationFixtureTestCase
{


    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendWhatsAppMessage::class]);
        Cache::flush();
    }

    public function test_notification_preference_defaults_and_commands(): void
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'wa_' . uniqid(),
            'phone_number' => '2348011223344',
        ]);

        $service = app(NotificationPreferenceService::class);
        $prefs = $service->getPreferences($contact);

        $this->assertTrue($prefs->opt_in_order_alerts);
        $this->assertTrue($prefs->opt_in_status_alerts);
        $this->assertFalse($prefs->is_paused);

        // Command: STOP
        $result = $service->handleInboundCommand($contact, 'STOP');
        $this->assertEquals('paused', $result['status']);
        $prefs->refresh();
        $this->assertTrue($prefs->is_paused);
        $this->assertNotEmpty($prefs->consent_audit_log);

        // Non-critical alert should now be blocked
        $check = $service->canReceiveNotification($contact, 'status_alerts', false);
        $this->assertFalse($check['allowed']);
        $this->assertEquals('alerts_paused', $check['reason']);

        // Critical alert should bypass pause
        $criticalCheck = $service->canReceiveNotification($contact, 'status_alerts', true);
        $this->assertTrue($criticalCheck['allowed']);
        $this->assertEquals('critical_override', $criticalCheck['reason']);

        // Command: RESUME ALERTS
        $resumeResult = $service->handleInboundCommand($contact, 'RESUME ALERTS');
        $this->assertEquals('resumed', $resumeResult['status']);
        $prefs->refresh();
        $this->assertFalse($prefs->is_paused);
    }

    public function test_notification_quiet_hours_and_category_opt_out(): void
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'wa_' . uniqid(),
            'phone_number' => '2348022334455',
        ]);

        $service = app(NotificationPreferenceService::class);
        $service->updateCategoryOptIn($contact, 'order', false);

        $orderCheck = $service->canReceiveNotification($contact, 'order_created', false);
        $this->assertFalse($orderCheck['allowed']);
        $this->assertEquals('opted_out_of_order_created', $orderCheck['reason']);

        $statusCheck = $service->canReceiveNotification($contact, 'vendor_approved', false);
        $this->assertTrue($statusCheck['allowed']);

        // Set quiet hours spanning current time
        $now = Carbon::now();
        $start = $now->copy()->subHours(1)->format('H:i');
        $end = $now->copy()->addHours(1)->format('H:i');
        $service->setQuietHours($contact, $start, $end);

        $quietCheck = $service->canReceiveNotification($contact, 'vendor_approved', false);
        $this->assertFalse($quietCheck['allowed']);
        $this->assertEquals('quiet_hours_active', $quietCheck['reason']);
    }

    public function test_language_preference_and_commands(): void
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'wa_' . uniqid(),
            'phone_number' => '2348033445566',
        ]);

        $service = app(LanguagePreferenceService::class);

        // Default language is English
        $this->assertEquals('en', $service->getLanguage($contact));

        // Menu command
        $menu = $service->handleLanguageCommand($contact, 'CHANGE LANGUAGE');
        $this->assertEquals('menu', $menu['type']);
        $this->assertArrayHasKey('yo', $menu['options']);

        // Selection: 2 -> Yoruba
        $selection = $service->handleLanguageCommand($contact, '2');
        $this->assertEquals('confirmation', $selection['type']);
        $this->assertEquals('yo', $selection['locale']);
        $this->assertEquals('yo', $service->getLanguage($contact));

        // Invalid locale is rejected
        $this->assertFalse($service->setLanguage($contact, 'invalid_lang'));

        // Distinctive multi-word suggestion without auto-switch
        $suggestion = $service->detectSuggestion("Bawo ni, se daadaa ni?");
        $this->assertEquals('yo', $suggestion);
        // Persisted language remains unchanged unless user confirms
        $this->assertEquals('yo', $service->getLanguage($contact));
    }

    public function test_ai_budget_rate_limits_and_circuit_breaker(): void
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'wa_' . uniqid(),
            'phone_number' => '2348044556677',
        ]);

        $vendor = new Vendor();
        $vendor->f_name = 'Budget';
        $vendor->l_name = 'Vendor';
        $vendor->phone = '2348044556677';
        $vendor->email = 'budget@mytijaara.test';
        $vendor->password = bcrypt('secret');
        $vendor->status = 1;
        $vendor->save();

        $budgetService = app(AiBudgetService::class);

        // Within limits initially
        $check = $budgetService->canInvokeAi($contact, $vendor);
        $this->assertTrue($check['allowed']);

        // Exhaust hourly contact budget
        $contactHourlyKey = "whatsapp_ai_turns_contact_{$contact->id}_" . Carbon::now()->format('YmdH');
        Cache::put($contactHourlyKey, AiBudgetService::MAX_HOURLY_TURNS_PER_CONTACT, 3600);

        $exhaustedCheck = $budgetService->canInvokeAi($contact, $vendor);
        $this->assertFalse($exhaustedCheck['allowed']);
        $this->assertEquals('contact_hourly_rate_limit', $exhaustedCheck['reason']);

        // Reset and test circuit breaker
        Cache::flush();
        for ($i = 0; $i < AiBudgetService::MAX_CONSECUTIVE_FAILURES; $i++) {
            $budgetService->recordFailure(new \RuntimeException('Meta timeout'));
        }

        $cbCheck = $budgetService->canInvokeAi($contact, $vendor);
        $this->assertFalse($cbCheck['allowed']);
        $this->assertEquals('circuit_breaker_active', $cbCheck['reason']);
    }

    public function test_prompt_sanitization_and_history_truncation(): void
    {
        $budgetService = app(AiBudgetService::class);

        $dirtyInput = "My bank account is 0123456789 and password: SuperSecretPassword! Contact test@example.com";
        $sanitized = $budgetService->sanitizePromptInput($dirtyInput);

        $this->assertStringNotContainsString('0123456789', $sanitized);
        $this->assertStringNotContainsString('SuperSecretPassword!', $sanitized);
        $this->assertStringNotContainsString('test@example.com', $sanitized);
        $this->assertStringContainsString('[REDACTED_NUMBER]', $sanitized);
        $this->assertStringContainsString('[REDACTED]', $sanitized);
        $this->assertStringContainsString('[REDACTED_EMAIL]', $sanitized);

        // History truncation
        $longHistory = range(1, 25);
        $truncated = $budgetService->limitHistory($longHistory, 10);
        $this->assertCount(10, $truncated);
        $this->assertEquals(16, $truncated[0]);
        $this->assertEquals(25, $truncated[9]);
    }

    public function test_support_case_lifecycle(): void
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'wa_' . uniqid(),
            'phone_number' => '2348055667788',
        ]);

        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'state' => 'ai_active',
        ]);

        $supportService = app(SupportCaseService::class);

        // 1. Create case
        $case = $supportService->createCase(
            contact: $contact,
            subject: 'Payment reconciliation required',
            category: 'payment',
            priority: 'urgent',
            initialMessage: 'My subscription payment was charged but not activated',
            conversation: $conversation
        );

        $this->assertStringStartsWith('TCK-', $case->ticket_id);
        $this->assertEquals('open', $case->status);
        $this->assertEquals('payment', $case->category);
        $this->assertEquals('urgent', $case->priority);
        // SLA for urgent is 2 hours
        $this->assertTrue($case->sla_expires_at->diffInHours(Carbon::now()) <= 2);

        // Conversation moved to human_support
        $conversation->refresh();
        $this->assertEquals('human_handoff', $conversation->state);

        // 2. Append vendor message
        $supportService->appendCustomerMessage($case, 'Here is my transaction reference: TX-12345');
        $case->refresh();
        $this->assertStringContainsString('TX-12345', $case->internal_notes);

        // 3. Resolve case
        $supportService->resolveCase($case, 'Payment manually verified and credited', $conversation);
        $case->refresh();
        $this->assertEquals('resolved', $case->status);
        $this->assertNotNull($case->resolved_at);

        // Conversation returned to ai_active
        $conversation->refresh();
        $this->assertEquals('ai_active', $conversation->state);

        // 4. Reopen within 48 hours
        $reopenResult = $supportService->reopenCase($case, 'Problem still occurs', $conversation);
        $this->assertTrue($reopenResult['success']);
        $case->refresh();
        $this->assertEquals('open', $case->status);
        $conversation->refresh();
        $this->assertEquals('human_handoff', $conversation->state);
    }
}
