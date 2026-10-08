<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use App\Models\Admin;
use App\Services\RegistrationPolicyService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\FlowControlController;
use Modules\WhatsAppVendorConcierge\app\Http\Middleware\AuthorizeWhatsAppOperations;
use Modules\WhatsAppVendorConcierge\app\Jobs\RunFlowControlOperation;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\Audit;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\Confirmation;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\EndpointProbe;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\Lifecycle;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\MediaReconciliation;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\MetaError;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\Permissions;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\PolicyLibrary;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\Presentation;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\RemoteDefinition;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\RuntimeSettings;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\TestRecipient;
use Modules\WhatsAppVendorConcierge\app\Services\FlowDefinitionValidator;
use phpseclib3\Crypt\RSA;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FlowControlCentreTest extends HardeningTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('admins', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id');
        });
        DB::table('admins')->insert(['id' => 1, 'role_id' => 1]);
        Schema::create('admin_roles', function (Blueprint $t) {
            $t->id();
            $t->text('modules');
            $t->boolean('status');
        });
        DB::table('admin_roles')->insert(['id' => 2, 'modules' => json_encode(['store', 'contact_messages']), 'status' => true]);
        config(['whatsapp-vendor-concierge.api.business_account_id' => '100', 'whatsapp-vendor-concierge.api.phone_number_id' => '200', 'whatsapp-vendor-flow.endpoint_url' => 'https://dashboard.mytijaara.test/webhooks/whatsapp/flow-data']);
    }

    public function test_permissions_fail_closed_and_can_be_assigned_per_role(): void
    {
        $p = app(Permissions::class);
        $this->assertFalse($p->allows((object) ['role_id' => 2], 'publish'));
        $this->assertTrue($p->allows((object) ['role_id' => 1], 'publish'));
        $this->assertFalse($p->allows((object) ['role_id' => 1], 'shell'));
        DB::table('wa_flow_control_permissions')->insert(['role_id' => 2, 'permission' => 'view']);
        $this->assertTrue($p->allows((object) ['role_id' => 2], 'view'));
        $this->assertFalse($p->allows((object) ['role_id' => 2], 'publish'));
    }

    public function test_audit_cannot_be_changed_or_deleted_in_database(): void
    {
        app(Audit::class)->record(1, 'publish', '300', 'failed', ['code' => 139000]);
        foreach (['update', 'delete'] as $verb) {
            try {
                $q = DB::table('wa_flow_control_audits');
                $verb === 'update' ? $q->update(['outcome' => 'succeeded']) : $q->delete();
                $this->fail('Immutable audit was modified.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('failed', DB::table('wa_flow_control_audits')->first()->outcome);
    }

    public function test_operations_are_idempotent_and_queued_without_meta_io(): void
    {
        $service = app(Lifecycle::class);
        $a = $service->enqueue(1, 'local', 'same-nonce');
        $this->assertSame($a, $service->enqueue(1, 'local', 'same-nonce'));
        $this->assertSame(1, DB::table('wa_flow_control_operations')->count());
        Queue::assertPushed(RunFlowControlOperation::class, 1);
        Http::assertNothingSent();
        $service->run($a);
        $this->assertSame('succeeded', DB::table('wa_flow_control_operations')->first()->state);
    }

    public function test_publication_failure_preserves_pointer_and_normalizes_meta_error(): void
    {
        $hash = hash_file('sha256', app(FlowDefinitionValidator::class)->path());
        DB::table('wa_vendor_flow_sync')->insert(['definition_version' => config('whatsapp-vendor-flow.definition_version'), 'asset_hash' => $hash, 'draft_flow_id' => '300', 'published_flow_id' => '299', 'status' => 'validated', 'created_at' => now(), 'updated_at' => now()]);
        config(['whatsapp-vendor-flow.enabled' => false, 'whatsapp-vendor-flow.flow_id' => '299']);
        Http::fake(['*/300?*' => Http::response(['id' => '300', 'status' => 'DRAFT', 'validation_errors' => [], 'health_status' => ['can_send_message' => 'AVAILABLE']]), '*/300/assets' => Http::response(['data' => [['asset_type' => 'FLOW_JSON', 'name' => 'flow.json', 'download_url' => 'https://mmg.whatsapp.net/test-asset']]]), 'https://mmg.whatsapp.net/test-asset' => Http::response(file_get_contents(app(FlowDefinitionValidator::class)->path())), '*/300/publish' => Http::response(['error' => ['code' => 139000, 'error_subcode' => 4233020, 'message' => 'secret-token reflected', 'fbtrace_id' => 'safe_trace']], 400)]);
        $service = app(Lifecycle::class);
        $id = $service->enqueue(1, 'publish', 'publish-nonce');
        $service->run($id);
        $op = DB::table('wa_flow_control_operations')->first();
        $this->assertSame('failed', $op->state);
        $result = json_decode($op->result, true);
        $this->assertSame(139000, $result['code']);
        $this->assertSame(4233020, $result['subcode']);
        $this->assertFalse($result['retry_safe']);
        $this->assertStringNotContainsString('secret-token', $op->result);
        $this->assertSame('299', DB::table('wa_vendor_flow_sync')->first()->published_flow_id);
        $this->assertFalse(config('whatsapp-vendor-flow.enabled'));
    }

    public function test_remote_inspection_cannot_publish_or_change_dispatch(): void
    {
        DB::table('wa_vendor_flow_sync')->insert(['definition_version' => config('whatsapp-vendor-flow.definition_version'), 'draft_flow_id' => '300', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(['*' => Http::response(['id' => '300', 'status' => 'DRAFT', 'validation_errors' => [], 'health_status' => ['entities' => [['errors' => [['error_code' => 141010]]]]]])]);
        $service = app(Lifecycle::class);
        $id = $service->enqueue(1, 'inspect', 'inspect-nonce');
        $service->run($id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET');
        $this->assertSame([141010], json_decode(DB::table('wa_flow_control_operations')->first()->result, true)['health_codes']);
    }

    public function test_runtime_settings_do_not_accept_secrets_or_arbitrary_keys(): void
    {
        app(RuntimeSettings::class)->put(['enabled' => false, 'fallback_to_chat' => true, 'rollout_percent' => 0]);
        $this->assertFalse(config('whatsapp-vendor-flow.enabled'));
        $this->assertTrue(config('whatsapp-vendor-flow.fallback_to_chat'));
        $this->expectException(\InvalidArgumentException::class);
        app(RuntimeSettings::class)->put(['access_token' => 'forbidden']);
    }

    public function test_meta_reflected_strings_and_trace_ids_are_redacted(): void
    {
        $e = MetaError::fromResponse(400, ['code' => 139000, 'message' => 'Authorization: sensitive', 'error_user_msg' => 'private document', 'fbtrace_id' => 'bad/token']);
        $this->assertNull($e->safe['trace_id']);
        $this->assertStringNotContainsString('sensitive', json_encode($e->safe));
        $this->assertStringNotContainsString('private document', json_encode($e->safe));
    }

    public function test_presentation_cannot_change_required_fields_or_legal_statements(): void
    {
        $service = app(Presentation::class);
        $base = app(FlowDefinitionValidator::class)->validate();
        $revised = $service->revised($base, ['screens.0.title' => 'Your owner details']);
        $this->assertSame('Your owner details', $revised['screens'][0]['title']);
        $this->assertSame($base['screens'][0]['layout'], $revised['screens'][0]['layout']);
        $this->expectException(\InvalidArgumentException::class);
        $service->revised($base, ['screens.0.layout.children.0.required' => 'false']);
    }

    public function test_presentation_rejects_html_and_dynamic_expressions(): void
    {
        $base = app(FlowDefinitionValidator::class)->validate();
        foreach (['<script>alert(1)</script>', '${data.token}'] as $text) {
            try {
                app(Presentation::class)->revised($base, ['screens.0.title' => $text]);
                $this->fail('Unsafe presentation was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_revoked_permission_blocks_an_already_queued_operation(): void
    {
        $id = app(Lifecycle::class)->enqueue(1, 'publish', 'revoked');
        DB::table('admins')->where('id', 1)->update(['role_id' => 2]);
        app(Lifecycle::class)->run($id);
        $this->assertSame('failed', DB::table('wa_flow_control_operations')->first()->state);
        $this->assertStringContainsString('permission was revoked', DB::table('wa_flow_control_operations')->first()->result);
        Http::assertNothingSent();
    }

    public function test_changed_definition_snapshot_blocks_execution(): void
    {
        $id = app(Lifecycle::class)->enqueue(1, 'publish', 'stale');
        DB::table('wa_flow_control_operations')->where('id', $id)->update(['context' => json_encode(['definition_version' => 'old-version', 'asset_hash' => str_repeat('0', 64), 'waba' => '100', 'phone_id' => '200'])]);
        app(Lifecycle::class)->run($id);
        $this->assertSame('failed', DB::table('wa_flow_control_operations')->first()->state);
        Http::assertNothingSent();
    }

    public function test_module_control_routes_use_admin_boundary_and_no_mutating_get(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->getName() ?? '', 'admin.whatsapp.flows.')) {
                continue;
            }
            $this->assertContains('admin', $route->gatherMiddleware());
            $this->assertContains(AuthorizeWhatsAppOperations::class, $route->gatherMiddleware());
            if (in_array($route->getName(), ['admin.whatsapp.flows.index', 'admin.whatsapp.flows.report', 'admin.whatsapp.flows.applications', 'admin.whatsapp.flows.application'], true)) {
                continue;
            }
            $this->assertNotContains('GET', $route->methods());
        }
    }

    public function test_fresh_test_recipient_blocks_existing_vendor_and_closed_window(): void
    {
        Schema::table('vendors', fn ($t) => $t->string('phone')->nullable());
        DB::table('vendors')->insert(['phone' => '+234 800-000-0001']);
        [$contact, $session, $conversation] = $this->application();
        config(['whatsapp-vendor-flow.test_phones' => ['2348000000001']]);
        $check = app(TestRecipient::class)->check('2348000000001');
        $this->assertTrue($check['existing_vendor']);
        $this->assertFalse($check['window_open']);
        $this->assertFalse($check['eligible']);
        $this->assertSame('••••0001', $check['recipient']);
        Http::assertNothingSent();
    }

    public function test_all_control_centre_tabs_render_without_exposing_credentials(): void
    {
        $dir = sys_get_temp_dir().'/flow-layout-'.bin2hex(random_bytes(6));
        mkdir($dir.'/layouts/admin', 0700, true);
        file_put_contents($dir.'/layouts/admin/app.blade.php', '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="bootstrap.min.css">@stack("css_or_js")</head><body>@yield("content")@stack("script_2")</body></html>');
        view()->getFinder()->prependLocation($dir);
        view()->share('errors', new ViewErrorBag);
        $admin = new Admin;
        $admin->forceFill(['id' => 1, 'role_id' => 1]);
        auth('admin')->setUser($admin);
        try {
            foreach (['overview', 'definition', 'policies', 'test', 'applications', 'analytics', 'diagnostics', 'settings', 'history'] as $tab) {
                $request = Request::create('/admin/whatsapp/flows', 'GET', ['tab' => $tab]);
                $request->setLaravelSession(app('session')->driver());
                app()->instance('request', $request);
                $html = app(FlowControlController::class)->index($request)->render();
                if ($export = getenv('FLOW_CONTROL_SYNTHETIC_PREVIEW')) {
                    if (! is_dir($export)) {
                        throw new \LogicException('Preview directory must already exist.');
                    }
                    file_put_contents($export.'/'.$tab.'.html', $html);
                }
                $this->assertStringContainsString('WhatsApp Flow Control Centre', $html);
                $this->assertStringNotContainsString('test-token', $html);
                $this->assertStringNotContainsString('test-secret', $html);
            }
        } finally {
            unlink($dir.'/layouts/admin/app.blade.php');
            rmdir($dir.'/layouts/admin');
            rmdir($dir.'/layouts');
            rmdir($dir);
        }
    }

    public function test_corrupt_runtime_settings_fail_closed_without_partial_application(): void
    {
        config(['whatsapp-vendor-flow.enabled' => true, 'whatsapp-vendor-flow.rollout_percent' => 0]);
        DB::table('wa_flow_control_settings')->insert(['key' => 'rollout_percent', 'value' => '100']);
        DB::table('wa_flow_control_settings')->insert(['key' => 'max_image_bytes', 'value' => '999999999']);
        app(RuntimeSettings::class)->apply();
        $this->assertFalse(config('whatsapp-vendor-flow.enabled'));
        $this->assertTrue(config('whatsapp-vendor-flow.fallback_to_chat'));
        $this->assertSame(0, config('whatsapp-vendor-flow.rollout_percent'));
    }

    public function test_cleanup_is_disabled_by_default_and_does_not_delete_audits(): void
    {
        config(['whatsapp-vendor-flow.cleanup_schedule' => 'disabled']);
        app(Audit::class)->record(1, 'fixture', null, 'succeeded');
        $this->artisan('whatsapp:flow-control-cleanup', ['--execute' => true])->assertSuccessful();
        $this->assertSame(1, DB::table('wa_flow_control_audits')->count());
        Http::assertNothingSent();
    }

    public function test_remote_definition_refuses_arbitrary_signed_download_origin(): void
    {
        Http::fake(['*/300/assets' => Http::response(['data' => [['name' => 'flow.json', 'asset_type' => 'FLOW_JSON', 'download_url' => 'https://attacker.invalid/secret']]])]);
        try {
            app(RemoteDefinition::class)->compare('300');
            $this->fail('Unapproved origin accepted');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Unapproved', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_confirmation_is_bound_to_admin_target_and_expiry(): void
    {
        $admin = new Admin;
        $admin->forceFill(['id' => 1, 'role_id' => 1]);
        auth('admin')->setUser($admin);
        $service = app(Confirmation::class);
        $hash = str_repeat('a', 64);
        $proof = $service->issue($hash, 'nonce');
        $service->verify($proof, $hash, 'nonce');
        $this->addToAssertionCount(1);
        config(['whatsapp-vendor-concierge.api.phone_number_id' => 'changed']);
        try {
            $service->verify($proof, $hash, 'nonce');
            $this->fail('Changed target accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        config(['whatsapp-vendor-concierge.api.phone_number_id' => '200']);
        $this->travel(6)->minutes();
        try {
            $service->verify($proof, $hash, 'nonce');
            $this->fail('Expired proof accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        } finally {
            $this->travelBack();
        }
    }

    public function test_signed_endpoint_probe_validates_encrypted_response_with_generated_test_key(): void
    {
        $path = sys_get_temp_dir().'/flow-test-key-'.bin2hex(random_bytes(8)).'.pem';
        $rsa = RSA::createKey(2048)->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');
        file_put_contents($path, $rsa->toString('PKCS8'));
        config(['whatsapp-vendor-flow.private_key_path' => $path, 'whatsapp-vendor-flow.private_key_passphrase' => null]);
        Http::fake(function ($request) use ($rsa) {
            $this->assertSame('sha256='.hash_hmac('sha256', $request->body(), 'test-secret'), $request->header('X-Hub-Signature-256')[0]);
            $data = json_decode($request->body(), true);
            $key = $rsa->decrypt(base64_decode($data['encrypted_aes_key']));
            $iv = base64_decode($data['initial_vector']);
            $bytes = base64_decode($data['encrypted_flow_data']);
            $plain = openssl_decrypt(substr($bytes, 0, -16), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, substr($bytes, -16));
            $this->assertSame('ping', json_decode($plain, true)['action']);
            $encrypted = openssl_encrypt(json_encode(['data' => ['status' => 'active']]), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, ~$iv, $tag);

            return Http::response(base64_encode($encrypted.$tag));
        });
        try {
            $result = app(EndpointProbe::class)->run();
            $this->assertSame('active', $result['endpoint']);
        } finally {
            unlink($path);
        }
    }

    public function test_policy_publication_and_selection_preserve_immutable_shared_contract(): void
    {
        (require base_path('database/migrations/2026_10_07_000002_create_registration_policy_evidence.php'))->up();
        $root = sys_get_temp_dir().'/flow-policy-test-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        config(['registration-policies.archive_disk' => 'registration_private', 'filesystems.disks.registration_private' => ['driver' => 'local', 'root' => $root, 'throw' => true]]);
        Storage::forgetDisk('registration_private');
        $library = app(PolicyLibrary::class);
        try {
            $library->publish('terms', 'synthetic-terms', '<p>Synthetic test terms only.</p>', 1);
            $library->publish('privacy', 'synthetic-privacy', '<p>Synthetic test privacy only.</p>', 1);
            $library->publish('terms', 'synthetic-terms', '<p>Synthetic test terms only.</p>', 1);
            $this->assertSame(2, DB::table('legal_policy_versions')->count());
            try {
                $library->publish('terms', 'synthetic-terms', '<p>Changed</p>', 1);
                $this->fail('Version overwritten');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
            config(['whatsapp-vendor-flow.enabled' => true]);
            $library->select('synthetic-terms', 'synthetic-privacy', 1);
            $manifest = app(RegistrationPolicyService::class)->manifest('en');
            $this->assertSame('synthetic-terms', $manifest['terms']['version']);
            $this->assertSame(hash('sha256', '<p>Synthetic test terms only.</p>'), $manifest['terms']['hash']);
            $this->assertFalse(config('whatsapp-vendor-flow.enabled'));
            $this->assertSame(0, DB::table('vendor_registration_consents')->count());
        } finally {
            foreach (glob($root.'/policy-documents/*.html') as $file) {
                chmod($file, 0600);
                unlink($file);
            }
            if (is_dir($root.'/policy-documents')) {
                rmdir($root.'/policy-documents');
            } rmdir($root);
        }
    }

    public function test_deprecation_protects_current_and_last_working_flow_without_meta_mutation(): void
    {
        DB::table('wa_vendor_flow_sync')->insert(['definition_version' => config('whatsapp-vendor-flow.definition_version'), 'published_flow_id' => '300', 'draft_flow_id' => '300', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $service = app(Lifecycle::class);
        $id = $service->enqueue(1, 'deprecate', 'retire-nonce', null, '300');
        $service->run($id);
        $this->assertSame('failed', DB::table('wa_flow_control_operations')->where('id', $id)->value('state'));
        Http::assertNothingSent();
        $this->assertSame('300', DB::table('wa_vendor_flow_sync')->value('published_flow_id'));
    }

    public function test_orphan_reconciliation_preserves_database_owned_media_and_preview_is_read_only(): void
    {
        $disk = Storage::fake('vendor_flow_private');
        $orphan = 'sessions/999/'.str_repeat('a', 48).'.jpg';
        $owned = 'sessions/998/'.str_repeat('b', 48).'.png';
        foreach ([$orphan, $owned] as $path) {
            $disk->put($path, 'synthetic fixture');
            touch($disk->path($path), now()->subDays(2)->timestamp);
        }
        DB::table('wa_vendor_flow_media')->insert(['flow_session_id' => 998, 'role' => 'logo', 'media_identity' => str_repeat('c', 64), 'path' => $owned, 'mime' => 'image/png', 'sha256' => str_repeat('d', 64), 'bytes' => 17, 'state' => 'staged', 'created_at' => now(), 'updated_at' => now()]);
        $service = app(MediaReconciliation::class);
        $preview = $service->run(false);
        $this->assertSame(1, $preview['orphan_candidates']);
        $this->assertSame(0, $preview['removed']);
        $disk->assertExists($orphan);
        $result = $service->run(true);
        $this->assertSame(1, $result['removed']);
        $disk->assertMissing($orphan);
        $disk->assertExists($owned);
    }

    public function test_meta_trace_cannot_reflect_a_configured_secret(): void
    {
        $error = MetaError::fromResponse(400, ['code' => 190, 'fbtrace_id' => 'test-secret']);
        $this->assertNull($error->safe['trace_id']);
        $this->assertStringNotContainsString('test-secret', json_encode($error->safe));
    }

    public function test_older_immutable_audits_remain_accessible_through_independent_pagination(): void
    {
        $admin = new Admin;
        $admin->forceFill(['id' => 1, 'role_id' => 1]);
        auth('admin')->setUser($admin);
        for ($i = 0; $i < 31; $i++) {
            app(Audit::class)->record(1, 'fixture', 'record:'.$i, 'succeeded');
        }
        $request = Request::create('/admin/whatsapp/flows', 'GET', ['tab' => 'history', 'audit_page' => 2]);
        $request->setLaravelSession(app('session')->driver());
        app()->instance('request', $request);
        $audit = app(FlowControlController::class)->index($request)->getData()['audit'];
        $this->assertSame(31, $audit->total());
        $this->assertSame(2, $audit->currentPage());
        $this->assertCount(1, $audit);
        $this->assertSame('record:0', $audit->first()->target);
    }
}
