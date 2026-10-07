<?php

namespace Tests\Architecture;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use Modules\WhatsAppVendorConcierge\app\Models\VendorFlowSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Services\FlowSubmissionProcessor;
use Modules\WhatsAppVendorConcierge\app\Services\FlowMediaService;

class MySqlVendorFlowConcurrencyTest extends \Tests\TestCase
{
    private ?FullCoreDatabase $fixture = null;

    protected function setUp(): void
    {
        if (getenv('ISOLATION_MYSQL') !== '1') {
            $this->markTestSkipped('Requires explicitly enabled loopback scratch fixture.');
        }
        parent::setUp();
        $this->fixture = new FullCoreDatabase;
        $this->fixture->create();
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::fake('vendor_flow_private');
        Storage::fake('registration_private');
        Storage::fake('policy_archive');
        config(['registration-policies.archive_disk' => 'policy_archive']);
        Storage::fake('public');
        config(['cache.default' => 'array', 'mail.status' => false, 'whatsapp-vendor-flow.enabled' => true, 'whatsapp-vendor-flow.flow_id' => '987', 'whatsapp-vendor-flow.definition_version' => 'fixture', 'registration-policies.current.en' => ['terms' => 'test-terms.en', 'privacy' => 'test-privacy.en']]);
        foreach (glob(module_path('WhatsAppVendorConcierge', 'database/migrations/*.php')) as $migration) {
            if (basename($migration) === '2026_10_07_000003_create_vendor_flow_sessions.php') {
                DB::statement("SET SESSION default_storage_engine='MyISAM'");
            }
            (require $migration)->up();
        }
        (require base_path('database/migrations/2026_10_07_000002_create_registration_policy_evidence.php'))->up();
        (require base_path('database/migrations/2026_10_07_000001_create_vendor_security_tokens_table.php'))->up();
        (require base_path('database/migrations/2026_10_07_000004_enforce_registration_transactional_storage.php'))->up();
        DB::table('business_settings')->insert([['key' => 'toggle_store_registration', 'value' => '1'], ['key' => 'subscription_business_model', 'value' => '0'], ['key' => 'commission_business_model', 'value' => '1']]);
        DB::table('modules')->insert(['id' => 1, 'module_name' => 'Fixture', 'module_type' => 'grocery', 'status' => 1]);
        DB::table('zones')->insert(['id' => 1, 'name' => 'Fixture', 'status' => 1, 'coordinates' => DB::raw("ST_GeomFromText('POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))',".POINT_SRID.')')]);
        DB::table('module_zone')->insert(['module_id' => 1, 'zone_id' => 1]);
        foreach (['terms', 'privacy'] as $kind) {
            Storage::disk('policy_archive')->put('fixture/'.$kind, 'Fixture '.$kind);
            \App\Models\LegalPolicyVersion::create(['policy' => $kind, 'version' => 'test-'.$kind.'.en', 'locale' => 'en', 'content_hash' => hash('sha256', 'Fixture '.$kind), 'document_url' => 'https://example.test/immutable/'.$kind, 'storage_object' => 'fixture/'.$kind, 'effective_at' => now()->subDay(), 'created_at' => now()]);
        }
        $this->app->instance(\App\Services\VendorSelfRegistrationService::class, \Mockery::mock(\App\Services\VendorSelfRegistrationService::class)->makePartial()->shouldAllowMockingProtectedMethods()->shouldReceive('zoneContains')->andReturn(true)->getMock());
    }

    protected function tearDown(): void
    {
        try {
            $this->fixture?->destroy();
        } finally {
            parent::tearDown();
        }
    }

    public function test_forward_repair_preserves_policy_rows_and_restores_real_transactions_and_foreign_keys(): void
    {
        $before = DB::table('legal_policy_versions')->orderBy('id')->get()->toJson();
        $keys = DB::select("SELECT TABLE_NAME AS table_name,CONSTRAINT_NAME AS constraint_name FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='FOREIGN KEY' AND TABLE_NAME IN ('vendor_security_tokens','vendor_registration_consents')");
        foreach ($keys as $key) {
            \Illuminate\Support\Facades\Schema::table($key->table_name, fn ($t) => $t->dropForeign($key->constraint_name));
        }
        foreach (['vendor_security_tokens', 'vendor_registration_consents', 'vendor_registration_media', 'legal_policy_versions'] as $table) {
            DB::statement("ALTER TABLE `$table` ENGINE=MyISAM");
        }
        $guard = app(\Modules\WhatsAppVendorConcierge\app\Services\FlowStorageInvariant::class);
        try {
            $guard->assertSafe();
            $this->fail('Nontransactional storage must prevent activation.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        (require base_path('database/migrations/2026_10_07_000004_enforce_registration_transactional_storage.php'))->up();
        (require base_path('database/migrations/2026_10_07_000004_enforce_registration_transactional_storage.php'))->up();
        $guard->assertSafe();
        $this->assertSame($before, DB::table('legal_policy_versions')->orderBy('id')->get()->toJson());
        $foreign = DB::select("SELECT DELETE_RULE AS delete_rule FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='vendor_registration_consents'");
        $this->assertCount(3, $foreign);
        foreach ($foreign as $key) {
            $this->assertSame('RESTRICT', $key->delete_rule);
        }
        $count = DB::table('wa_vendor_flow_events')->count();
        DB::beginTransaction();
        DB::table('wa_vendor_flow_events')->insert(['event' => 'synthetic_rollback', 'created_at' => now()]);
        DB::rollBack();
        $this->assertSame($count, DB::table('wa_vendor_flow_events')->count());
    }

    public function test_two_connections_cannot_complete_one_session_and_duplicates_remain_unique(): void
    {
        $c = WhatsAppContact::create(['whatsapp_id' => '2348000000001', 'phone_number' => '2348000000001']);
        $h = OnboardingSession::create(['contact_id' => $c->id, 'status' => 'started', 'expires_at' => now()->addHour()]);
        $manifest = app(\App\Services\RegistrationPolicyService::class)->manifest('en');
        $raw = bin2hex(random_bytes(32));
        $s = VendorFlowSession::create(['onboarding_session_id' => $h->id, 'contact_id' => $c->id, 'token_hash' => hash('sha256', $raw), 'sender' => $c->whatsapp_id, 'flow_id' => '987', 'definition_version' => 'fixture', 'locale' => 'en', 'state' => 'flow_submitted', 'screen' => 'REVIEW', 'policy_manifest' => $manifest, 'draft' => ['first_name' => 'Fixture', 'surname' => 'Owner', 'email' => 'flow@example.test', 'store_name' => 'Fixture store', 'address' => 'Fixture address', 'module_id' => '1', 'zone_id' => '1', 'latitude' => '5', 'longitude' => '5', 'minimum_delivery_time' => '20', 'maximum_delivery_time' => '30', 'delivery_time_unit' => 'min', 'business_plan' => 'commission-base', 'pickup_zone_ids' => [], 'terms_agreed' => true, 'privacy_acknowledged' => true, 'terms_version' => 'test-terms.en', 'privacy_version' => 'test-privacy.en', 'presentation_hash' => $manifest['presentation_hash']], 'expires_at' => now()->addHour()]);
        $image = \Illuminate\Http\UploadedFile::fake()->image('fixture.png', 20, 20);
        $bytes = file_get_contents($image->getRealPath());
        $media = \Mockery::mock(FlowMediaService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $media->shouldReceive('downloadEncrypted')->andReturn($bytes);
        $this->app->instance(FlowMediaService::class, $media);
        $media->stage($s, 'logo', [['media_id' => '100']]);
        $media->stage($s, 'cover', [['media_id' => '101']]);
        $cfg = config('database.connections.core_fixture');
        $other = new \PDO('mysql:host=127.0.0.1;port='.$cfg['port'].';dbname='.$cfg['database'], $cfg['username'], $cfg['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $other->exec('SET innodb_lock_wait_timeout=1');
        DB::beginTransaction();
        VendorFlowSession::whereKey($s->id)->lockForUpdate()->first();
        $other->beginTransaction();
        try {
            $other->query('SELECT id FROM wa_vendor_flow_sessions WHERE id='.(int) $s->id.' FOR UPDATE');
            $this->fail('Second worker should block on session lock.');
        } catch (\PDOException $e) {
            $this->assertSame(1205, (int) $e->errorInfo[1]);
        } finally {
            $other->rollBack();
            DB::rollBack();
        }
        $in = \Modules\WhatsAppVendorConcierge\app\DTOs\FlowSubmission::fromMessage(['id' => 'wamid.mysql.fixture', 'from' => $c->whatsapp_id, 'interactive' => ['nfm_reply' => ['response_json' => json_encode(['flow_token' => $raw, 'flow_id' => '987', 'definition_version' => 'fixture', 'submitted' => true])]]]);
        $processor = app(FlowSubmissionProcessor::class);
        $this->assertSame('completed', $processor->process($in));
        $this->assertSame('duplicate', $processor->process($in));
        $this->assertDatabaseCount('vendors', 1);
        $this->assertDatabaseCount('vendor_registration_consents', 2);
        try {
            DB::table('wa_vendor_flow_receipts')->insert(['message_id' => 'wamid.mysql.fixture', 'flow_session_id' => $s->id, 'state' => 'received', 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('Receipt constraint must reject duplicate.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0]);
        }
        try {
            DB::table('vendor_registration_consents')->delete();
            $this->fail('Evidence trigger must protect deletion.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame('45000', $e->errorInfo[0]);
        }
    }
}
