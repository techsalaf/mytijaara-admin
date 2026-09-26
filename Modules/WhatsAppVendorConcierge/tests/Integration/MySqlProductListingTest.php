<?php

namespace Modules\WhatsAppVendorConcierge\tests\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Services\LaunchTaxonomyService;
use Modules\WhatsAppVendorConcierge\app\Services\ProductListingFlow;
use Tests\Architecture\FullCoreDatabase;
use Tests\TestCase;

class MySqlProductListingTest extends TestCase
{
    private ?FullCoreDatabase $fixture = null;

    protected function setUp(): void
    {
        if (getenv('ISOLATION_MYSQL') !== '1') {
            $this->markTestSkipped('Requires disposable loopback MySQL/MariaDB.');
        }parent::setUp();
        $this->fixture = new FullCoreDatabase;
        $this->fixture->create();
        foreach (glob(base_path('Modules/WhatsAppVendorConcierge/database/migrations/*.php')) as $migration) {
            (require $migration)->up();
        }
        config(['cache.default' => 'array', 'mail.status' => false]);
        Storage::fake('local');
        Storage::fake('public');
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        try {
            $this->fixture?->destroy();
        } finally {
            parent::tearDown();
        }
    }

    public function test_mysql_taxonomy_is_additive_idempotent_and_product_confirm_is_atomic(): void
    {
        DB::table('modules')->insert(['id' => 1, 'module_name' => 'Food', 'module_type' => 'food', 'status' => 1]);
        $s = app(LaunchTaxonomyService::class);
        $preview = $s->run(false, null, true);
        $this->assertSame(0, DB::table('categories')->count());
        $first = $s->run(true, null, true);
        $count = DB::table('categories')->count();
        $s->run(true, null, true);
        $this->assertSame($count, DB::table('categories')->count());
        $this->assertSame(7, DB::table('units')->count());
        DB::table('vendors')->insert(['id' => 1, 'f_name' => 'Fixture', 'phone' => '2348000000001', 'email' => 'fixture@example.test', 'password' => bcrypt('Fixture-only-123!'), 'status' => 1]);
        DB::table('stores')->insert(['id' => 1, 'vendor_id' => 1, 'module_id' => 1, 'name' => 'Fixture', 'phone' => '2348000000001', 'status' => 1, 'active' => 1, 'item_section' => 1, 'store_business_model' => 'commission', 'delivery_time' => '20-30']);
        $contact = WhatsAppContact::create(['whatsapp_id' => '2348000000001', 'phone_number' => '2348000000001', 'vendor_id' => 1]);
        $c = WhatsAppConversation::create(['contact_id' => $contact->id, 'vendor_id' => 1, 'state' => 'ai_active']);
        $flow = app(ProductListingFlow::class);
        $say = fn ($text) => $flow->receive($c, $contact, new WhatsAppMessage(['type' => 'text', 'raw_text' => $text]));
        $say('add product');
        $say('skip');
        $say('Rice and beans');
        $say((string) DB::table('categories')->where('parent_id', 0)->value('id'));
        $say('skip');
        $say('Freshly prepared rice and beans');
        $say('1800');
        $say('skip');
        $say('yes');
        $say('08:00');
        $say('21:00');
        $say('skip');
        $say('skip');
        $say('skip');
        $say('skip');
        $this->assertSame('review', $flow->current($c)->step);
        $reply = $say('confirm');
        $this->assertStringContainsString('Product #', $reply);
        $this->assertSame(1, DB::table('items')->count());
        $say('confirm');
        $this->assertSame(1, DB::table('items')->count());
    }
}
