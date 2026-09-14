<?php

namespace Modules\WhatsAppVendorConcierge\tests\Unit;

use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Support\Facades\Http;
use Modules\WhatsAppVendorConcierge\app\Jobs\SendVendorStatusNotification;
use Tests\TestCase;

class ApprovedTemplateContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'whatsapp-vendor-concierge.api.business_account_id' => 'test-waba',
            'whatsapp-vendor-concierge.api.access_token' => 'secret-test-token',
            'whatsapp-vendor-concierge.api.version' => 'v21.0',
            'whatsapp-vendor-concierge.messaging.templates.approved' => 'vendor_application_approved',
            'whatsapp-vendor-concierge.messaging.template_locales.approved' => 'en',
        ]);
        Http::preventStrayRequests();
    }

    public function test_sender_matches_actual_approved_and_denied_template_parameter_order(): void
    {
        $store = new Store(['name' => 'Ibadan Store']);
        $store->setRelation('translations', collect());
        $vendor = new Vendor(['f_name' => 'Amina']);
        $job = new class(2, 'approved', 'Please provide the missing document.') extends SendVendorStatusNotification {
            public function components($type, $store, $vendor): array
            {
                return $this->buildTemplateComponents($type, $store, $vendor, 'https://dashboard.mytijaara.test/vendor/auth/login');
            }
        };
        $this->assertSame([['type' => 'text', 'text' => 'Ibadan Store']], $job->components('approved', $store, $vendor)[0]['parameters']);
        $this->assertSame([
            ['type' => 'text', 'text' => 'Amina'],
            ['type' => 'text', 'text' => 'Please provide the missing document.'],
        ], $job->components('denied', $store, $vendor)[0]['parameters']);
    }

    private function mockTemplate(string $language = 'en', string $status = 'APPROVED', string $body = 'Business: {{1}}'): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [[
            'name' => 'vendor_application_approved', 'language' => $language, 'status' => $status,
            'components' => [['type' => 'BODY', 'text' => $body]],
        ]]])]);
    }

    public function test_audit_passes_exact_approved_contract_without_sending_messages(): void
    {
        $this->mockTemplate();
        $this->artisan('whatsapp:check-templates', ['--event' => ['approved']])->assertExitCode(0);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/test-waba/message_templates'));
        Http::assertSentCount(1);
    }

    public function test_audit_fails_wrong_locale_and_lists_the_available_locale(): void
    {
        $this->mockTemplate();
        config(['whatsapp-vendor-concierge.messaging.template_locales.approved' => 'en_US']);
        $this->artisan('whatsapp:check-templates', ['--event' => ['approved']])
            ->expectsOutputToContain('available locales: en')->assertExitCode(1);
    }

    public function test_audit_fails_missing_templates(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);
        $this->artisan('whatsapp:check-templates', ['--event' => ['approved']])->assertExitCode(1);
    }

    public function test_audit_fails_unapproved_templates(): void
    {
        $this->mockTemplate(status: 'PENDING');
        $this->artisan('whatsapp:check-templates', ['--event' => ['approved']])->assertExitCode(1);
    }

    public function test_audit_fails_wrong_parameter_count(): void
    {
        $this->mockTemplate(body: 'Hello {{1}}, store {{2}}, URL {{3}}');
        $this->artisan('whatsapp:check-templates', ['--event' => ['approved']])->assertExitCode(1);
    }

    public function test_audit_does_not_print_provider_credentials_or_raw_errors(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'secret-test-token']], 401)]);
        $this->artisan('whatsapp:check-templates', ['--event' => ['approved']])
            ->expectsOutput('Meta template lookup failed (HTTP 401, code 190).')->assertExitCode(1);
    }
}
