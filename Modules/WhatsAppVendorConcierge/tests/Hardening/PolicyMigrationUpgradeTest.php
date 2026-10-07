<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use App\Models\LegalPolicyVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PolicyMigrationUpgradeTest extends ApplicationFixtureTestCase
{
    public function test_public_policy_endpoint_serves_exact_archived_bytes_and_fails_closed_on_tampering(): void
    {
        (require base_path('database/migrations/2026_10_07_000002_create_registration_policy_evidence.php'))->up();
        Storage::fake('policy_archive');
        config(['registration-policies.archive_disk' => 'policy_archive']);
        $bytes = "<h2>Approved synthetic Terms</h2>\r\n<p>Exact bytes.</p>";
        $object = 'policy-documents/test-terms-en.html';
        Storage::disk('policy_archive')->put($object, $bytes);
        LegalPolicyVersion::create(['policy' => 'terms', 'version' => 'test-terms-en', 'locale' => 'en', 'content_hash' => hash('sha256', $bytes), 'document_url' => 'https://example.test/registration-policy-documents/test-terms-en', 'storage_object' => $object, 'effective_at' => now('UTC'), 'created_at' => now('UTC')]);
        $response = $this->get('/registration-policy-documents/test-terms-en');
        $response->assertOk()->assertContent($bytes)->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('ETag', '"'.hash('sha256', $bytes).'"');
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        Storage::disk('policy_archive')->put($object, 'changed');
        $this->get('/registration-policy-documents/test-terms-en')->assertStatus(503);
        $this->get('/registration-policy-documents/unknown')->assertNotFound();
    }

    public function test_upgrade_preserves_existing_vendor_without_acceptance_backfill(): void
    {
        $id = DB::table('vendors')->insertGetId(['f_name' => 'Synthetic legacy', 'l_name' => 'Owner', 'email' => 'legacy@example.test', 'phone' => '2348000000099', 'password' => 'synthetic-unusable', 'status' => 1]);
        $before = (array) DB::table('vendors')->where('id', $id)->first();
        (require base_path('database/migrations/2026_10_07_000001_create_vendor_security_tokens_table.php'))->up();
        (require base_path('database/migrations/2026_10_07_000002_create_registration_policy_evidence.php'))->up();
        $this->assertSame($before, (array) DB::table('vendors')->where('id', $id)->first());
        $this->assertDatabaseCount('legal_policy_versions', 0);
        $this->assertDatabaseCount('vendor_registration_consents', 0);
        $this->assertTrue(Schema::hasTable('vendor_registration_media'));
        $this->assertTrue(Schema::hasTable('vendor_security_tokens'));
        $foreign = DB::select('PRAGMA foreign_key_list(vendor_registration_consents)');
        $this->assertCount(3, $foreign);
        foreach ($foreign as $key) {
            $this->assertSame('RESTRICT', $key->on_delete);
        }
        $indexes = DB::select('PRAGMA index_list(vendor_registration_consents)');
        $this->assertTrue(collect($indexes)->contains(fn ($i) => $i->name === 'registration_consent_store_policy_unique' && $i->unique === 1));
    }

    public function test_transactional_sqlite_migration_failure_rolls_back_partial_ddl(): void
    {
        DB::unprepared('CREATE TABLE trigger_fixture (id INTEGER)');
        DB::unprepared('CREATE TRIGGER legal_policy_versions_immutable_update BEFORE UPDATE ON trigger_fixture BEGIN SELECT 1; END');
        try {
            DB::transaction(function () {
                (require base_path('database/migrations/2026_10_07_000002_create_registration_policy_evidence.php'))->up();
            });
            $this->fail('Injected trigger-name collision must fail migration.');
        } catch (QueryException) {
            $this->assertFalse(Schema::hasTable('legal_policy_versions'));
            $this->assertFalse(Schema::hasTable('vendor_registration_consents'));
            $this->assertFalse(Schema::hasTable('vendor_registration_media'));
            $this->assertTrue(Schema::hasTable('trigger_fixture'));
        }
    }

    public function test_synthetic_archive_installer_is_idempotent_and_refuses_normal_database(): void
    {
        (require base_path('database/migrations/2026_10_07_000002_create_registration_policy_evidence.php'))->up();
        Storage::fake('policy_archive');
        config(['registration-policies.archive_disk' => 'policy_archive']);
        $path = module_path('WhatsAppVendorConcierge', 'resources/flows/fixtures/install_sandbox_policies.php');
        $manifest = require $path;
        $this->assertSame('en', $manifest['locale']);
        $this->assertSame(hash('sha256', 'Development/test-only terms'), $manifest['terms']['hash']);
        $this->assertSame($manifest, require $path);
        $this->assertDatabaseCount('legal_policy_versions', 2);
        $this->assertDatabaseCount('vendor_registration_consents', 0);
        config(['database.connections.sqlite.database' => 'ordinary_database']);
        $this->expectException(\LogicException::class);
        require $path;
    }
}
