<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use App\Models\LegalPolicyVersion;
use App\Services\RegistrationPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Http\UploadedFile;
use Modules\WhatsAppVendorConcierge\app\Models\VendorFlowSession;
use Modules\WhatsAppVendorConcierge\app\DTOs\FlowSubmission;
use Modules\WhatsAppVendorConcierge\app\Services\FlowSubmissionProcessor;
use Modules\WhatsAppVendorConcierge\app\Services\FlowMediaService;
use Modules\WhatsAppVendorConcierge\app\Services\FlowDataExchangeService;
use PHPUnit\Framework\Attributes\DataProvider;

class VendorFlowIntegrationTest extends ApplicationFixtureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require base_path('database/migrations/2026_10_07_000002_create_registration_policy_evidence.php'))->up();
        (require base_path('database/migrations/2026_10_07_000001_create_vendor_security_tokens_table.php'))->up();
        \Illuminate\Support\Facades\Schema::table('vendors', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->string('auth_token')->nullable();
            $t->string('remember_token')->nullable();
            $t->string('login_remember_token')->nullable();
        });
        \Illuminate\Support\Facades\Schema::create('vendor_employees', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('vendor_id');
            $t->string('auth_token')->nullable();
            $t->string('login_remember_token')->nullable();
            $t->timestamps();
        });
        Storage::fake('vendor_flow_private');
        Storage::fake('registration_private');
        Storage::fake('policy_archive');
        config(['registration-policies.archive_disk' => 'policy_archive']);
        DB::table('zones')->insert(['id' => 1, 'name' => 'Fixture zone', 'status' => 1]);
        DB::table('modules')->insert(['id' => 1, 'module_name' => 'Fixture module', 'module_type' => 'grocery', 'status' => 1]);
        DB::table('module_zone')->insert(['module_id' => 1, 'zone_id' => 1]);
        config(['whatsapp-vendor-flow.enabled' => true, 'whatsapp-vendor-flow.flow_id' => '987', 'whatsapp-vendor-flow.definition_version' => 'vendor-onboarding-2026-10-07.1', 'whatsapp-vendor-concierge.api.phone_number_id' => '123', 'whatsapp-vendor-concierge.api.business_account_id' => '456', 'registration-policies.current.en' => ['terms' => 'test-terms.en', 'privacy' => 'test-privacy.en']]);
        foreach (['terms', 'privacy'] as $kind) {
            Storage::disk('policy_archive')->put('test-only/'.$kind, 'Development/test-only '.$kind);
            LegalPolicyVersion::create(['policy' => $kind, 'version' => 'test-'.$kind.'.en', 'locale' => 'en', 'content_hash' => hash('sha256', 'Development/test-only '.$kind), 'document_url' => 'https://example.test/immutable/'.$kind, 'storage_object' => 'test-only/'.$kind, 'effective_at' => now()->subDay(), 'created_at' => now('UTC')]);
        }
    }

    private function draft(array $overrides = [], bool $media = true): array
    {
        [$c,$host,$conversation] = $this->application();
        $manifest = app(RegistrationPolicyService::class)->manifest('en');
        $token = bin2hex(random_bytes(32));
        $values = array_replace(['first_name' => 'Fixture', 'surname' => 'Owner', 'email' => 'fixture@example.test', 'store_name' => 'Fixture shop', 'address' => 'Fixture address', 'module_id' => '1', 'zone_id' => '1', 'latitude' => '5', 'longitude' => '5', 'minimum_delivery_time' => '20', 'maximum_delivery_time' => '30', 'delivery_time_unit' => 'min', 'business_plan' => 'commission-base', 'package_id' => '', 'pickup_zone_ids' => [], 'terms_agreed' => true, 'privacy_acknowledged' => true, 'terms_version' => 'test-terms.en', 'privacy_version' => 'test-privacy.en', 'presentation_hash' => $manifest['presentation_hash']], $overrides);
        $s = VendorFlowSession::create(['onboarding_session_id' => $host->id, 'contact_id' => $c->id, 'token_hash' => hash('sha256', $token), 'sender' => $c->whatsapp_id, 'flow_id' => '987', 'definition_version' => config('whatsapp-vendor-flow.definition_version'), 'locale' => 'en', 'state' => 'flow_submitted', 'screen' => 'REVIEW', 'draft' => $values, 'policy_manifest' => $manifest, 'expires_at' => now()->addHour()]);
        if ($media) {
            $this->stage($s, 'logo', '100');
            $this->stage($s, 'cover', '101');
        }

        return [$s, $token, $conversation];
    }

    private function stage(VendorFlowSession $s, string $role, string $id, string $content = ''): void
    {
        if ($content === '') {
            $image = UploadedFile::fake()->image('fixture.png', 20, 20);
            $content = file_get_contents($image->getRealPath());
        }
        $service = \Mockery::mock(FlowMediaService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('downloadEncrypted')->andReturn($content);
        $this->app->instance(FlowMediaService::class, $service);
        $service->stage($s, $role, [['media_id' => $id]]);
    }

    private function submission(VendorFlowSession $s, string $token, string $id = 'wamid.fixture'): FlowSubmission
    {
        return FlowSubmission::fromMessage(['id' => $id, 'from' => $s->sender, 'type' => 'interactive', 'interactive' => ['type' => 'nfm_reply', 'nfm_reply' => ['response_json' => json_encode(['flow_token' => $token, 'flow_id' => $s->flow_id, 'definition_version' => $s->definition_version, 'submitted' => true])]]]);
    }

    public function test_complete_submission_is_atomic_idempotent_private_and_pending(): void
    {
        [$s,$raw] = $this->draft();
        $this->assertSame([], Storage::disk('public')->allFiles());
        $in = $this->submission($s, $raw);
        $p = app(FlowSubmissionProcessor::class);
        $this->assertSame('completed', $p->process($in));
        $this->assertSame('duplicate', $p->process($in));
        $this->assertSame('invalid_submission', $p->process($this->submission($s, $raw, 'wamid.replay')));
        $this->assertDatabaseCount('vendors', 1);
        $this->assertDatabaseCount('stores', 1);
        $this->assertDatabaseCount('vendor_registration_consents', 2);
        $this->assertNull(DB::table('vendors')->value('status'));
        $this->assertSame(0, (int) DB::table('stores')->value('status'));
        $this->assertCount(2, Storage::disk('public')->allFiles());
        $this->assertNull($s->fresh()->draft);
        $this->assertStringNotContainsString($raw, json_encode($s->fresh()->toArray()));
        $this->assertStringNotContainsString('fixture@example.test', DB::table('wa_vendor_flow_sessions')->value('draft') ?? '');
        Queue::assertPushed(\Modules\WhatsAppVendorConcierge\app\Jobs\SendFlowRegistrationNotification::class, 1);
    }

    public static function invalidValues(): array
    {
        return [
            'first name' => [['first_name' => '']], 'missing email' => [['email' => '']], 'missing latitude' => [['latitude' => '']],
            'surname' => [['surname' => '']], 'email' => [['email' => 'wrong']], 'latitude' => [['latitude' => '91']], 'longitude' => [['longitude' => '181']], 'delivery minimum' => [['minimum_delivery_time' => '']], 'delivery maximum' => [['maximum_delivery_time' => '']], 'delivery ordering' => [['maximum_delivery_time' => '1']], 'delivery unit' => [['delivery_time_unit' => 'seconds']],
            'module' => [['module_id' => '999']], 'zone' => [['zone_id' => '999']], 'plan' => [['business_plan' => 'invented']], 'subscription without package' => [['business_plan' => 'subscription-base']], 'wrong terms version' => [['terms_version' => 'stale']], 'wrong privacy version' => [['privacy_version' => 'stale']], 'missing terms agreement' => [['terms_agreed' => false]], 'missing privacy acknowledgement' => [['privacy_acknowledged' => false]], 'presentation binding' => [['presentation_hash' => str_repeat('0', 64)]]];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_domain_values_never_create_accounts(array $changes): void
    {
        [$s,$raw] = $this->draft($changes);
        $this->assertSame('correction_required', app(FlowSubmissionProcessor::class)->process($this->submission($s, $raw)));
        $this->assertDatabaseCount('vendors', 0);
        $this->assertDatabaseCount('vendor_registration_consents', 0);
    }

    public function test_logo_and_cover_are_both_required_and_kyc_is_optional(): void
    {
        [$s,$raw] = $this->draft([], false);
        $this->stage($s, 'logo', '100');
        $this->assertSame('correction_required', app(FlowSubmissionProcessor::class)->process($this->submission($s, $raw)));
        $this->assertDatabaseCount('vendors', 0);
        $this->stage($s, 'cover', '101');
        $s->fresh()->update(['state' => 'flow_submitted']);
        $this->assertSame('completed', app(FlowSubmissionProcessor::class)->process($this->submission($s, $raw)));
        $this->assertSame('def.png', DB::table('stores')->value('tin_certificate_image'));
    }

    public function test_wrong_sender_flow_version_expiry_and_session_binding_are_rejected(): void
    {
        [$s,$raw] = $this->draft();
        $valid = $this->submission($s, $raw);
        $p = app(FlowSubmissionProcessor::class);
        foreach ([['sender' => '2348999999999'], ['flowId' => '999'], ['definitionVersion' => 'old'], ['tokenHash' => str_repeat('0', 64)]] as $change) {
            $in = new FlowSubmission(...array_replace(['sender' => $valid->sender, 'messageId' => $valid->messageId, 'flowId' => $valid->flowId, 'definitionVersion' => $valid->definitionVersion, 'tokenHash' => $valid->tokenHash, 'response' => $valid->response, 'receivedAt' => $valid->receivedAt], $change));
            $this->assertSame('invalid_submission', $p->process($in));
        }
        $s->update(['expires_at' => now()->subSecond()]);
        $this->assertSame('invalid_submission', $p->process($valid));
        $this->assertDatabaseCount('vendors', 0);
    }

    public function test_malformed_and_unexpected_payloads_never_enter_conversation_or_ai_jobs(): void
    {
        [$s,$raw] = $this->draft();
        $json = json_encode(['flow_token' => $raw, 'flow_id' => '987', 'definition_version' => $s->definition_version, 'submitted' => true, 'password' => 'DO-NOT-STORE', 'extra' => 'sensitive']);
        $message = ['id' => 'wamid.webhook', 'from' => $s->sender, 'type' => 'interactive', 'interactive' => ['type' => 'nfm_reply', 'nfm_reply' => ['response_json' => $json]]];
        $body = json_encode(['entry' => [['id' => '456', 'changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '123'], 'messages' => [$message]]]]]]]);
        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret')], $body)->assertOk();
        Queue::assertPushed(\Modules\WhatsAppVendorConcierge\app\Jobs\ProcessVendorFlowSubmission::class, fn ($j) => ! str_contains(serialize($j), 'DO-NOT-STORE') && ! str_contains(serialize($j), $raw));
        Queue::assertNotPushed(\Modules\WhatsAppVendorConcierge\app\Jobs\ProcessIncomingWhatsAppMessage::class);
        $this->assertDatabaseCount('whatsapp_messages', 0);
        $this->expectException(\JsonException::class);
        FlowSubmission::fromMessage(array_replace_recursive($message, ['interactive' => ['nfm_reply' => ['response_json' => '{broken']]]));
    }

    public function test_wrong_signature_cannot_dispatch_a_flow_submission(): void
    {
        $this->postJson('/webhooks/whatsapp', ['entry' => []], ['X-Hub-Signature-256' => 'sha256=wrong'])->assertUnauthorized();
        Queue::assertNothingPushed();
    }

    public function test_media_spoofing_and_cross_session_reuse_are_rejected(): void
    {
        [$s] = $this->draft();
        try {
            $this->stage($s, 'tin_document', '200', '<html>not an image</html>');
            $this->fail('MIME spoof must fail.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('JPEG', $e->getMessage());
        }
        $host = \Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession::create(['contact_id' => $s->contact_id, 'status' => 'started', 'expires_at' => now()->addHour()]);
        $other = $s->replicate();
        $other->onboarding_session_id = $host->id;
        $other->token_hash = hash('sha256', 'other');
        $other->save();
        $this->expectException(\InvalidArgumentException::class);
        app(FlowMediaService::class)->stage($other, 'logo', [['media_id' => '100']]);
    }

    public function test_disabled_feature_and_sync_dry_run_make_no_meta_calls(): void
    {
        [$s,$raw] = $this->draft();
        config(['whatsapp-vendor-flow.enabled' => false]);
        $this->assertSame('flow_disabled', app(FlowSubmissionProcessor::class)->process($this->submission($s, $raw)));
        $this->artisan('whatsapp:flow-sync', ['--action' => 'create'])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->artisan('whatsapp:flow-sync', ['--action' => 'remote', '--publish' => true])->expectsOutputToContain('requires')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_local_definition_validation_and_fixtures_are_dry_runs(): void
    {
        $this->artisan('whatsapp:flow-validate', ['--fixture' => module_path('WhatsAppVendorConcierge', 'resources/flows/fixtures/valid_submission.json')])->assertSuccessful();
        $this->artisan('whatsapp:flow-validate', ['--fixture' => module_path('WhatsAppVendorConcierge', 'resources/flows/fixtures/invalid_submission.json')])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_policy_records_and_evidence_are_immutable(): void
    {
        $this->expectException(\LogicException::class);
        LegalPolicyVersion::first()->update(['content_hash' => str_repeat('0', 64)]);
    }

    public function test_database_failure_rolls_back_accounts_and_both_evidence_rows(): void
    {
        [$s,$raw] = $this->draft();
        DB::connection()->beforeExecuting(function ($sql) {
            if (str_starts_with($sql, 'insert') && str_contains($sql, 'vendor_registration_consents')) {
                throw new \RuntimeException('Fixture persistence failure');
            }
        });
        try {
            app(FlowSubmissionProcessor::class)->process($this->submission($s, $raw));
            $this->fail('Failure must propagate safely.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Flow registration temporarily unavailable.', $e->getMessage());
        }
        $this->assertDatabaseCount('vendors', 0);
        $this->assertDatabaseCount('stores', 0);
        $this->assertDatabaseCount('vendor_registration_consents', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_credential_setup_route_is_single_use_purpose_bound_and_never_approves(): void
    {
        [$s,$raw] = $this->draft();
        app(FlowSubmissionProcessor::class)->process($this->submission($s, $raw));
        $s = $s->fresh();
        $vendor = \App\Models\Vendor::findOrFail($s->vendor_id);
        $store = \App\Models\Store::findOrFail($s->store_id);
        $tokens = app(\App\Services\VendorSecurityTokenService::class);
        $token = $tokens->issue($vendor, \App\Services\VendorSecurityTokenService::FLOW_SETUP, $store);
        $url = '/whatsapp/flow/password';
        $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertFalse($tokens->resetPassword($token, \App\Services\VendorSecurityTokenService::WEB_RESET, 'Changed-Only!456'));
        $this->post($url, ['setup_token' => $token, 'password' => 'Changed-Only!456', 'password_confirmation' => 'Changed-Only!456'])->assertOk();
        $this->post($url, ['setup_token' => $token, 'password' => 'Changed-Only!456', 'password_confirmation' => 'Changed-Only!456'])->assertStatus(410);
        $this->assertNull($vendor->fresh()->status);
        $this->assertSame(0, (int) $store->fresh()->status);
        $this->assertNull($vendor->fresh()->auth_token);
        $this->assertFalse(app(\App\Services\VendorAuthenticationEligibility::class)->evaluate($vendor->fresh(), $store->fresh())->eligible);
        $this->assertGuest('vendor');
        $this->assertDatabaseHas('wa_vendor_flow_events', ['event' => 'credential_setup_completed']);
        $expired = $tokens->issue($vendor, \App\Services\VendorSecurityTokenService::FLOW_SETUP, $store);
        $this->travel(16)->minutes();
        $this->post($url, ['setup_token' => $expired, 'password' => 'Changed-Only!456', 'password_confirmation' => 'Changed-Only!456'])->assertStatus(410);
        $this->travelBack();
    }

    public function test_encrypted_draft_progress_and_correction_preserve_previous_fields(): void
    {
        [$s,$raw] = $this->draft([], false);
        $s->update(['state' => 'flow_offered', 'screen' => 'OWNER', 'draft' => []]);
        $exchange = app(FlowDataExchangeService::class);
        $init = $exchange->handle(['version' => '3.0', 'action' => 'INIT', 'flow_token' => $raw]);
        $this->assertSame('OWNER', $init['screen']);
        $bad = $exchange->handle(['action' => 'data_exchange', 'screen' => 'OWNER', 'flow_token' => $raw, 'data' => ['first_name' => 'Fixture', 'surname' => '', 'email' => 'wrong', 'password' => 'IGNORE']]);
        $this->assertArrayHasKey('error_message', $bad['data']);
        $good = $exchange->handle(['action' => 'data_exchange', 'screen' => 'OWNER', 'flow_token' => $raw, 'data' => ['first_name' => 'Fixture', 'surname' => 'Owner', 'email' => 'owner@example.test', 'password' => 'IGNORE']]);
        $this->assertSame('STORE', $good['screen']);
        $this->assertSame('Fixture', $s->fresh()->draft['first_name']);
        $this->assertArrayNotHasKey('password', $s->fresh()->draft);
        $this->assertStringNotContainsString('owner@example.test', DB::table('wa_vendor_flow_sessions')->where('id', $s->id)->value('draft'));
        $back = $exchange->handle(['action' => 'BACK', 'flow_token' => $raw]);
        $this->assertSame('OWNER', $back['screen']);
        $this->assertSame('Fixture', $back['data']['first_name']);
    }

    public function test_expired_cleanup_never_deletes_another_successful_attempt(): void
    {
        [$s] = $this->draft();
        $files = Storage::disk('vendor_flow_private')->allFiles();
        $s->update(['expires_at' => now()->subMinute()]);
        $this->artisan('whatsapp:flow-operations', ['--cleanup' => true])->assertSuccessful();
        $this->assertSame($files, Storage::disk('vendor_flow_private')->allFiles());
        $this->artisan('whatsapp:flow-operations', ['--cleanup' => true, '--execute' => true])->assertSuccessful();
        $this->assertSame([], Storage::disk('vendor_flow_private')->allFiles());
        $this->assertSame('failed_terminal', $s->fresh()->state);
        $this->artisan('whatsapp:flow-operations', ['--cleanup' => true, '--execute' => true])->assertSuccessful();
    }

    public function test_database_enforces_immutable_policy_even_without_model_events(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('legal_policy_versions')->update(['content_hash' => str_repeat('0', 64)]);
    }

    public function test_media_publication_failure_retries_the_existing_application(): void
    {
        [$s,$raw] = $this->draft();
        $service = $this->app->make(\App\Services\VendorSelfRegistrationService::class);
        $proxy = \Mockery::mock(\App\Services\VendorSelfRegistrationService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $proxy->shouldReceive('zoneContains')->andReturn(true);
        $proxy->shouldReceive('publishPreparedMedia')->once()->andThrow(new \RuntimeException('Fixture storage failure'));
        $this->app->instance(\App\Services\VendorSelfRegistrationService::class, $proxy);
        try {
            app(FlowSubmissionProcessor::class)->process($this->submission($s, $raw));
            $this->fail('Publication failure should request retry.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Flow registration temporarily unavailable.', $e->getMessage());
        }
        $this->assertDatabaseCount('vendors', 1);
        $this->assertDatabaseCount('vendor_registration_consents', 2);
        $this->assertNull($s->fresh()->consumed_at);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->app->instance(\App\Services\VendorSelfRegistrationService::class, $service);
        $this->assertSame('completed', app(FlowSubmissionProcessor::class)->process($this->submission($s, $raw)));
        $this->assertDatabaseCount('vendors', 1);
    }

    public function test_duplicate_normalized_email_is_a_safe_correction(): void
    {
        DB::table('vendors')->insert(['f_name' => 'Existing', 'email' => ' FIXTURE@EXAMPLE.TEST ', 'phone' => '2348999999999', 'password' => bcrypt('Fixture-Only!123')]);
        [$s,$raw] = $this->draft();
        $this->assertSame('correction_required', app(FlowSubmissionProcessor::class)->process($this->submission($s, $raw)));
        $this->assertDatabaseCount('vendors', 1);
    }

    public function test_meta_errors_are_reported_without_tokens_or_arbitrary_reflected_text(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'REFLECTED SECRET test-token']], 401)]);
        $this->expectExceptionMessage('Meta API error 190 / 0 (HTTP 401).');
        app(\Modules\WhatsAppVendorConcierge\app\Services\FlowMetaClient::class)->request('GET', '987');
    }

    public function test_failure_to_offer_the_flow_keeps_chat_fallback(): void
    {
        [$contact,$session,$conversation] = $this->application();
        config(['whatsapp-vendor-flow.test_phones' => [$contact->whatsapp_id]]);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190]], 401)]);
        $this->assertFalse(app(\Modules\WhatsAppVendorConcierge\app\Services\FlowOnboardingService::class)->offer($session, $contact, $conversation));
        $this->assertDatabaseHas('wa_vendor_flow_events', ['event' => 'fallback_to_chat']);
    }

    public function test_successful_cleanup_removes_only_private_copies(): void
    {
        [$s,$raw] = $this->draft();
        app(FlowSubmissionProcessor::class)->process($this->submission($s, $raw));
        $public = Storage::disk('public')->allFiles();
        $this->artisan('whatsapp:flow-operations', ['--cleanup' => true, '--execute' => true])->assertSuccessful();
        $this->assertSame($public, Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('vendor_flow_private')->allFiles());
        $this->assertSame([], Storage::disk('registration_private')->allFiles());
        $this->assertDatabaseCount('vendor_registration_consents', 2);
    }

    public function test_sync_is_idempotent_and_never_publishes_by_default(): void
    {
        config(['whatsapp-vendor-flow.endpoint_url' => 'https://example.test/webhooks/whatsapp/flow-data']);
        Http::fakeSequence()->push(['data' => []])->push(['id' => '777'])->push(['success' => true])->push(['id' => '777', 'status' => 'DRAFT', 'validation_errors' => []]);
        $this->artisan('whatsapp:flow-sync', ['--action' => 'create', '--execute' => true])->assertSuccessful();
        $this->artisan('whatsapp:flow-sync', ['--action' => 'create', '--execute' => true])->assertSuccessful();
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/publish'));
        $this->assertDatabaseHas('wa_vendor_flow_sync', ['draft_flow_id' => '777', 'status' => 'validated']);
    }

    public function test_failed_replacement_preserves_published_pointer(): void
    {
        DB::table('wa_vendor_flow_sync')->insert(['definition_version' => config('whatsapp-vendor-flow.definition_version'), 'draft_flow_id' => '777', 'published_flow_id' => '987', 'asset_hash' => 'old', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        Http::fakeSequence()->push(['id' => '777', 'status' => 'DRAFT'])->push(['success' => false, 'validation_errors' => [['error' => 'invalid']]]);
        $this->artisan('whatsapp:flow-sync', ['--action' => 'upload', '--execute' => true])->assertFailed();
        $this->assertDatabaseHas('wa_vendor_flow_sync', ['published_flow_id' => '987']);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/publish'));
    }

    public function test_all_state_edges_are_explicit_and_unknown_edges_are_rejected(): void
    {
        [$s] = $this->draft();
        $sm = app(\Modules\WhatsAppVendorConcierge\app\Services\FlowStateMachine::class);
        foreach (\Modules\WhatsAppVendorConcierge\app\Services\FlowStateMachine::EDGES as $from => $destinations) {
            foreach ($destinations as $to) {
                $s->update(['state' => $from]);
                $sm->transition($s, $to);
                $this->assertSame($to, $s->fresh()->state);
            }
        }
        $this->expectException(\LogicException::class);
        $sm->transition($s, 'invented_state');
    }

    public static function journeyPlans(): array
    {
        return ['commission' => ['commission-base'], 'subscription' => ['subscription-base']];
    }

    #[DataProvider('journeyPlans')]
    public function test_all_seven_screens_complete_using_endpoint_media_exchange(string $plan): void
    {
        \Illuminate\Support\Facades\Schema::create('subscription_packages', function ($t) {
            $t->id();
            $t->string('module_type');
            $t->boolean('status');
            $t->string('package_name');
        });
        if ($plan === 'subscription-base') {
            DB::table('business_settings')->where('key', 'subscription_business_model')->update(['value' => '1']);
            DB::table('subscription_packages')->insert([
                ['id' => 1, 'module_type' => 'all', 'status' => 0, 'package_name' => 'Inactive synthetic package'],
                ['id' => 2, 'module_type' => 'rental', 'status' => 1, 'package_name' => 'Wrong module package'],
                ['id' => 3, 'module_type' => 'all', 'status' => 1, 'package_name' => 'Eligible synthetic package'],
            ]);
        }
        [$s,$raw] = $this->draft(['business_plan' => $plan, 'package_id' => $plan === 'subscription-base' ? '3' : ''], false);
        $values = $s->draft;
        $s->update(['state' => 'flow_offered', 'screen' => 'OWNER', 'draft' => []]);
        $exchange = app(FlowDataExchangeService::class);
        $exchange->handle(['action' => 'INIT', 'flow_token' => $raw]);
        $image = UploadedFile::fake()->image('fixture.png', 20, 20);
        $media = \Mockery::mock(FlowMediaService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $media->shouldReceive('downloadEncrypted')->andReturn(file_get_contents($image->getRealPath()));
        $this->app->instance(FlowMediaService::class, $media);
        foreach (FlowDataExchangeService::SCREENS as $index => $screen) {
            $data = array_intersect_key($values, array_flip(FlowDataExchangeService::FIELDS[$screen]));
            if ($screen === 'PLAN_LOGO') {
                $data['media'] = [['media_id' => '100']];
            }if ($screen === 'COVER') {
                $data['media'] = [['media_id' => '101']];
            }
            if ($screen === 'REVIEW') {
                $data['review_hash'] = $exchange->response($s->fresh(), 'REVIEW')['data']['review_hash'];
            }
            $response = $exchange->handle(['action' => 'data_exchange', 'screen' => $screen, 'flow_token' => $raw, 'data' => $data]);
            $this->assertSame(FlowDataExchangeService::SCREENS[$index + 1] ?? 'SUCCESS', $response['screen']);
            if ($response['screen'] === 'PLAN_LOGO' && $plan === 'subscription-base') {
                $this->assertSame(['3'], array_column($response['data']['packages'], 'id'));
            }
        }
        $this->assertDatabaseCount('vendors', 0);
        $params = $response['data']['extension_message_response']['params'];
        $this->assertArrayNotHasKey('email', $params);
        $submission = FlowSubmission::fromMessage(['id' => 'wamid.synthetic.journey', 'from' => $s->sender, 'interactive' => ['nfm_reply' => ['response_json' => json_encode($params)]]]);
        $processor = app(FlowSubmissionProcessor::class);
        $this->assertSame('completed', $processor->process($submission));
        $this->assertSame('duplicate', $processor->process($submission));
        $this->assertDatabaseCount('vendors', 1);
        $this->assertDatabaseCount('stores', 1);
        $this->assertDatabaseCount('vendor_registration_consents', 2);
        $vendor = \App\Models\Vendor::first();
        $store = \App\Models\Store::first();
        $this->assertNull($vendor->status);
        $this->assertSame(0, (int) $store->status);
        $this->assertSame($plan === 'subscription-base' ? 'none' : 'commission', $store->store_business_model);
        $this->assertSame($plan === 'subscription-base' ? 3 : null, $store->package_id);
        $this->assertFalse(app(\App\Services\VendorAuthenticationEligibility::class)->evaluate($vendor, $store)->eligible);
    }

    public function test_subscription_package_is_revalidated_and_remains_unpaid(): void
    {
        \Illuminate\Support\Facades\Schema::create('subscription_packages', function ($t) {
            $t->id();
            $t->string('module_type');
            $t->boolean('status');
            $t->string('package_name');
        });
        DB::table('business_settings')->where('key', 'subscription_business_model')->update(['value' => '1']);
        DB::table('subscription_packages')->insert(['id' => 3, 'module_type' => 'all', 'status' => 1, 'package_name' => 'Fixture']);
        [$s,$raw] = $this->draft(['business_plan' => 'subscription-base', 'package_id' => '3']);
        $this->assertSame('completed', app(FlowSubmissionProcessor::class)->process($this->submission($s, $raw)));
        $this->assertSame('none', DB::table('stores')->value('store_business_model'));
        $this->assertSame(3, (int) DB::table('stores')->value('package_id'));
    }

    public function test_optional_tin_image_is_promoted_with_required_branding(): void
    {
        [$s,$raw] = $this->draft(['tin' => 'Fixture-TIN', 'tin_expire_date' => '2027-12-31']);
        $this->stage($s, 'tin_document', '102');
        $this->assertSame('completed', app(FlowSubmissionProcessor::class)->process($this->submission($s, $raw)));
        $this->assertCount(3, Storage::disk('public')->allFiles());
        $this->assertNotSame('def.png', DB::table('stores')->value('tin_certificate_image'));
    }

    public function test_setup_notification_uses_fragment_and_retries_without_account_recreation(): void
    {
        [$s,$raw] = $this->draft();
        app(FlowSubmissionProcessor::class)->process($this->submission($s, $raw));
        config(['app.url' => 'https://example.test']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.notification']]])]);
        $job = new \Modules\WhatsAppVendorConcierge\app\Jobs\SendFlowRegistrationNotification($s->id);
        $job->handle();
        $job->handle();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['text']['body'], '/whatsapp/flow/password#setup=') && ! str_contains($r['text']['body'], '?token='));
        $this->assertDatabaseCount('vendors', 1);
        $this->assertDatabaseHas('wa_vendor_flow_sessions', ['id' => $s->id, 'notification_status' => 'sent']);
    }

    public function test_flow_payload_is_redacted_even_at_generic_privacy_boundary(): void
    {
        $p = ['id' => 'wamid.defense', 'from' => '2348000000001', 'type' => 'interactive', 'interactive' => ['type' => 'nfm_reply', 'nfm_reply' => ['response_json' => 'SENSITIVE']]];
        $safe = \Modules\WhatsAppVendorConcierge\app\Services\InboundPrivacy::redact($p);
        $this->assertSame('unsupported', $safe['type']);
        $this->assertStringNotContainsString('SENSITIVE', json_encode($safe));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_rental_pickup_zones_are_required_and_revalidated(): void
    {
        require base_path('tests/Architecture/fixtures/PublishedRental.php');
        DB::table('modules')->where('id', 1)->update(['module_type' => 'rental']);
        [$s,$raw] = $this->draft(['pickup_zone_ids' => []]);
        $p = app(FlowSubmissionProcessor::class);
        $this->assertSame('correction_required', $p->process($this->submission($s, $raw)));
        $s = $s->fresh();
        $d = $s->draft;
        $d['pickup_zone_ids'] = ['999'];
        $s->update(['draft' => $d, 'state' => 'flow_submitted']);
        $this->assertSame('correction_required', $p->process($this->submission($s, $raw)));
        $s = $s->fresh();
        $d = $s->draft;
        $d['pickup_zone_ids'] = ['1'];
        $s->update(['draft' => $d, 'state' => 'flow_submitted']);
        $this->assertSame('completed', $p->process($this->submission($s, $raw)));
        $this->assertSame('[1]', DB::table('stores')->value('pickup_zone_id'));
    }

    public function test_committed_recovery_works_after_correlation_expiry(): void
    {
        [$s,$raw] = $this->draft();
        $service = $this->app->make(\App\Services\VendorSelfRegistrationService::class);
        $proxy = \Mockery::mock(\App\Services\VendorSelfRegistrationService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $proxy->shouldReceive('zoneContains')->andReturn(true);
        $proxy->shouldReceive('publishPreparedMedia')->once()->andThrow(new \RuntimeException('Fixture storage failure'));
        $this->app->instance(\App\Services\VendorSelfRegistrationService::class, $proxy);
        try {
            app(FlowSubmissionProcessor::class)->process($this->submission($s, $raw));
        } catch (\RuntimeException $e) {
        }
        $this->app->instance(\App\Services\VendorSelfRegistrationService::class, $service);
        $s->fresh()->update(['expires_at' => now()->subMinute()]);
        $this->artisan('whatsapp:flow-operations', ['--retry' => true, '--execute' => true])->assertSuccessful();
        $this->assertDatabaseCount('vendors', 1);
        $this->assertNotNull($s->fresh()->consumed_at);
    }

    public function test_publication_requires_both_explicit_flags_and_matching_asset(): void
    {
        $asset = app(\Modules\WhatsAppVendorConcierge\app\Services\FlowDefinitionValidator::class)->path();
        DB::table('wa_vendor_flow_sync')->insert(['definition_version' => config('whatsapp-vendor-flow.definition_version'), 'draft_flow_id' => '777', 'published_flow_id' => '987', 'asset_hash' => hash_file('sha256', $asset), 'status' => 'validated', 'created_at' => now(), 'updated_at' => now()]);
        Http::fakeSequence()->push(['id' => '777', 'status' => 'DRAFT'])->push(['id' => '777', 'status' => 'DRAFT', 'validation_errors' => []])->push(['success' => true]);
        $this->artisan('whatsapp:flow-sync', ['--action' => 'remote', '--execute' => true, '--publish' => true])->assertSuccessful();
        $this->assertDatabaseHas('wa_vendor_flow_sync', ['published_flow_id' => '777', 'status' => 'published']);
        $this->assertSame('987', config('whatsapp-vendor-flow.flow_id'));
    }

    public function test_reselecting_earlier_owned_media_uses_that_selection(): void
    {
        [$s] = $this->draft();
        $original = DB::table('wa_vendor_flow_media')->where('role', 'logo')->value('path');
        $this->stage($s, 'logo', '102');
        $this->assertNotSame(basename($original), app(FlowMediaService::class)->file($s, 'logo')->getClientOriginalName());
        app(FlowMediaService::class)->stage($s, 'logo', [['media_id' => '100']]);
        $this->assertSame(basename($original), app(FlowMediaService::class)->file($s, 'logo')->getClientOriginalName());
        $this->assertDatabaseCount('wa_vendor_flow_media', 3);
    }

    public function test_legacy_flow_adapter_cannot_build_a_generic_chat_job(): void
    {
        $this->expectException(\LogicException::class);
        \Modules\WhatsAppVendorConcierge\app\Jobs\ProcessIncomingWhatsAppMessage::dispatchFlow(['flow_token' => 'DO-NOT-STORE'], []);
    }

    public function test_final_agreement_cannot_reuse_old_draft_controls(): void
    {
        [$s,$raw] = $this->draft();
        $s->update(['state' => 'flow_draft']);
        $exchange = app(FlowDataExchangeService::class);
        $v = $s->draft;
        unset($v['terms_agreed']);
        $v['review_hash'] = $exchange->response($s, 'REVIEW')['data']['review_hash'];
        $response = $exchange->handle(['action' => 'data_exchange', 'screen' => 'REVIEW', 'flow_token' => $raw, 'data' => $v]);
        $this->assertArrayHasKey('error_message', $response['data']);
        $this->assertDatabaseCount('vendors', 0);
    }

    public function test_stale_review_is_rejected_and_successful_submission_is_sealed(): void
    {
        [$s,$raw] = $this->draft();
        $s->update(['state' => 'flow_draft']);
        $exchange = app(FlowDataExchangeService::class);
        $v = $s->draft;
        $v['review_hash'] = $exchange->response($s, 'REVIEW')['data']['review_hash'];
        $changed = $s->draft;
        $changed['first_name'] = 'Changed';
        $s->update(['draft' => $changed]);
        $out = $exchange->handle(['action' => 'data_exchange', 'screen' => 'REVIEW', 'flow_token' => $raw, 'data' => $v]);
        $this->assertArrayHasKey('error_message', $out['data']);
        $s = $s->fresh();
        $v = $s->draft;
        $v['review_hash'] = $exchange->response($s, 'REVIEW')['data']['review_hash'];
        $out = $exchange->handle(['action' => 'data_exchange', 'screen' => 'REVIEW', 'flow_token' => $raw, 'data' => $v]);
        $this->assertSame('SUCCESS', $out['screen']);
        $out = $exchange->handle(['action' => 'data_exchange', 'screen' => 'OWNER', 'flow_token' => $raw, 'data' => ['first_name' => 'Tampered', 'surname' => 'Owner', 'email' => 'attacker@example.test']]);
        $this->assertSame('SUCCESS', $out['screen']);
        $this->assertSame('Changed', $s->fresh()->draft['first_name']);
    }

    public function test_reopening_expired_flow_rotates_token_and_requires_fresh_agreement(): void
    {
        [$s,$raw,$conversation] = $this->draft();
        $s->update(['expires_at' => now()->subMinute()]);
        config(['whatsapp-vendor-flow.test_phones' => [$s->sender]]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.offer']]])]);
        $host = \Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession::findOrFail($s->onboarding_session_id);
        $contact = \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact::findOrFail($s->contact_id);
        $this->assertTrue(app(\Modules\WhatsAppVendorConcierge\app\Services\FlowOnboardingService::class)->offer($host, $contact, $conversation));
        $fresh = $s->fresh();
        $this->assertNotSame(hash('sha256', $raw), $fresh->token_hash);
        $this->assertTrue($fresh->expires_at->isFuture());
        $this->assertSame('Fixture', $fresh->draft['first_name']);
        $this->assertArrayNotHasKey('terms_agreed', $fresh->draft);
        $this->assertArrayNotHasKey('privacy_acknowledged', $fresh->draft);
    }

    public function test_definition_cannot_change_agreement_wording_without_evidence_contract(): void
    {
        $validator = app(\Modules\WhatsAppVendorConcierge\app\Services\FlowDefinitionValidator::class);
        $definition = $validator->validate();
        foreach ($definition['screens'][6]['layout']['children'] as &$component) {
            if (($component['name'] ?? null) === 'privacy_acknowledged') {
                $component['label'] = 'I consent to all processing.';
            }
        }unset($component);
        $this->expectException(\InvalidArgumentException::class);
        $validator->validate($definition);
    }

    public function test_canonical_long_text_is_supported_without_unverified_widget_limits(): void
    {
        $definition = app(\Modules\WhatsAppVendorConcierge\app\Services\FlowDefinitionValidator::class)->validate();
        foreach ($definition['screens'][0]['layout']['children'] as $component) {
            if (in_array($component['name'] ?? null, ['first_name', 'surname', 'email'], true)) {
                $this->assertSame('TextArea', $component['type']);
                $this->assertArrayNotHasKey('max-chars', $component);
            }
        }
        [$s,$raw] = $this->draft(['first_name' => str_repeat('A', 90), 'email' => str_repeat('a', 50).'@'.str_repeat('b', 30).'.example.test', 'store_name' => str_repeat('S', 200), 'address' => str_repeat('X', 450)]);
        $this->assertSame('completed', app(FlowSubmissionProcessor::class)->process($this->submission($s, $raw)));
        $this->assertSame(200, strlen(DB::table('stores')->value('name')));
    }

    public function test_changed_archive_bytes_reject_registration_without_evidence(): void
    {
        [$session, $raw] = $this->draft();
        Storage::disk('policy_archive')->put('test-only/terms', 'CHANGED SYNTHETIC POLICY');
        $this->assertSame('correction_required', app(FlowSubmissionProcessor::class)->process($this->submission($session, $raw)));
        $this->assertDatabaseCount('vendors', 0);
        $this->assertDatabaseCount('vendor_registration_consents', 0);
    }

    public function test_missing_or_unconfigured_archive_fails_closed(): void
    {
        Storage::disk('policy_archive')->delete('test-only/privacy');
        try {
            app(RegistrationPolicyService::class)->manifest('en');
            $this->fail('Missing archive must not be presented.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('policy', $e->errors());
        }
        config(['registration-policies.archive_disk' => null]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(RegistrationPolicyService::class)->manifest('en');
    }

    public function test_local_logs_storage_diagnostics_and_funnel_exclude_submission_secrets(): void
    {
        $observed = [];
        $this->app['events']->listen(\Illuminate\Log\Events\MessageLogged::class, function ($event) use (&$observed) {
            $observed[] = [$event->message, $event->context];
        });
        [$s, $raw] = $this->draft(['email' => 'private-marker@example.test']);
        $stored = DB::table('wa_vendor_flow_sessions')->where('id', $s->id)->value('draft');
        $this->assertStringNotContainsString('private-marker', $stored);
        $this->assertStringNotContainsString($raw, $stored);
        $json = json_encode(['flow_token' => $raw, 'flow_id' => '987', 'definition_version' => $s->definition_version, 'submitted' => true, 'media_url' => 'https://mmg.whatsapp.net/PRIVATE-MEDIA-MARKER', 'document' => 'PRIVATE-DOCUMENT-MARKER']);
        $message = ['id' => 'wamid.privacy', 'from' => $s->sender, 'type' => 'interactive', 'interactive' => ['type' => 'nfm_reply', 'nfm_reply' => ['response_json' => $json]]];
        $body = json_encode(['entry' => [['id' => '456', 'changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '123'], 'messages' => [$message]]]]]]]);
        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret')], $body)->assertOk();
        $sm = app(\Modules\WhatsAppVendorConcierge\app\Services\FlowStateMachine::class);
        $sm->event($s, 'flow_offered');
        $processor = app(FlowSubmissionProcessor::class);
        $in = FlowSubmission::fromMessage($message);
        $this->assertSame('completed', $processor->process($in));
        $this->assertSame('duplicate', $processor->process($in));
        $summary = app(\Modules\WhatsAppVendorConcierge\app\Services\FlowDiagnostics::class)->summary();
        $this->assertSame(100.0, $summary['completion_rate_percent']);
        $this->assertSame(1, (int) collect($summary['funnel'])->where('event', 'registration_completed')->first()->sessions);
        $logs = json_encode($observed);
        $this->assertNotEmpty($observed, 'Inspect actual logger events, not an empty log fixture.');
        $analytics = json_encode(DB::table('wa_vendor_flow_events')->get());
        foreach ([$logs, $analytics, json_encode($summary)] as $surface) {
            foreach ([$raw, 'private-marker@example.test', 'PRIVATE-MEDIA-MARKER', 'PRIVATE-DOCUMENT-MARKER', 'response_json'] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, $surface);
            }
        }
        $this->assertDatabaseCount('whatsapp_messages', 0);
        Queue::assertNotPushed(\Modules\WhatsAppVendorConcierge\app\Jobs\ProcessIncomingWhatsAppMessage::class);
    }

    public function test_wrong_locale_and_unavailable_publication_are_safe_corrections(): void
    {
        [$s, $raw] = $this->draft();
        $s->update(['locale' => 'fr']);
        $processor = app(FlowSubmissionProcessor::class);
        $this->assertSame('correction_required', $processor->process($this->submission($s, $raw, 'wamid.wrong-locale')));
        $s->refresh()->update(['locale' => 'en', 'state' => 'flow_submitted']);
        foreach (['future', 'retired'] as $state) {
            $version = 'test-terms-'.$state.'.en';
            LegalPolicyVersion::create(['policy' => 'terms', 'version' => $version, 'locale' => 'en', 'content_hash' => hash('sha256', 'Development/test-only terms'), 'document_url' => 'https://example.test/immutable/terms', 'storage_object' => 'test-only/terms', 'effective_at' => $state === 'future' ? now('UTC')->addHour() : now('UTC')->subHour(), 'retired_at' => $state === 'retired' ? now('UTC') : null, 'created_at' => now('UTC')]);
            config(['registration-policies.current.en.terms' => $version]);
            $s->refresh()->update(['state' => 'flow_submitted']);
            $this->assertSame('correction_required', $processor->process($this->submission($s, $raw, 'wamid.policy-'.$state)));
        }
        $this->assertDatabaseCount('vendors', 0);
        $this->assertDatabaseCount('stores', 0);
        $this->assertDatabaseCount('vendor_registration_consents', 0);
    }
}
