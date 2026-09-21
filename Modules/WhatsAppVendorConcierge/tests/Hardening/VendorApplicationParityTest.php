<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use Modules\WhatsAppVendorConcierge\app\DTOs\VendorApplicationDTO;
use App\Models\Module;
use App\Models\Store;
use App\Models\Vendor;
use App\Models\Zone;
use Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\VendorApplicationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;

class VendorApplicationParityTest extends ApplicationFixtureTestCase
{
    public function test_adapter_cannot_create_an_account_without_secure_credentials(): void
    {
        $dto = new VendorApplicationDTO(f_name: 'Applicant', l_name: 'Owner', phone: '2348000000999',
            email: 'fixture@example.test', business_name: 'Fixture', address: 'Fixture address',
            latitude: 5, longitude: 5, zone_id: 1, module_id: 1, source: 'whatsapp');
        foreach ([null, 'not-a-password-hash'] as $hash) {
            $dto->password_hash = $hash;
            try {
                app(VendorApplicationService::class)->submit($dto);
                $this->fail('Account created without secure credentials');
            } catch (\Illuminate\Validation\ValidationException $error) {
                $this->assertArrayHasKey('password', $error->errors());
                $this->assertSame(0, Vendor::count());
            }
        }
    }

    public function test_web_and_whatsapp_dto_produce_identical_canonical_outcomes(): void
    {
        $zone = Zone::create([
            'name' => 'Ibadan Zone',
            'coordinates' => null,
            'status' => 1,
            'restaurant_wise_topic' => 'zone_1',
            'customer_wise_topic' => 'cust_1',
            'deliveryman_wise_topic' => 'dm_1',
            'cash_on_delivery' => true,
            'digital_payment' => true,
        ]);

        $module = Module::create([
            'module_name' => 'Grocery Ibadan',
            'module_type' => 'grocery',
            'thumbnail' => 'grocery.png',
            'status' => 1,
            'stores_count' => 0,
            'all_zone_service' => 0,
        ]);

        \Illuminate\Support\Facades\DB::table('module_zone')->insert([
            'module_id' => $module->id,
            'zone_id' => $zone->id,
        ]);

        $service = app(VendorApplicationService::class);

        // 1. Web submission
        $webRequest = new Request([
            'f_name' => 'Adebayo',
            'l_name' => 'Ogunlesi',
            'phone' => '+2348011112222',
            'email' => 'adebayo@example.com',
            'name' => ['default' => 'Adebayo Groceries'],
            'address' => ['default' => 'Ring Road, Ibadan'],
            'latitude' => 7.3775,
            'longitude' => 3.9470,
            'zone_id' => $zone->id,
            'module_id' => $module->id,
            'password' => 'SecurePass123!',
            'business_plan' => 'commission-base',
            'minimum_delivery_time' => '20',
            'maximum_delivery_time' => '40',
            'delivery_time_type' => 'min',
        ]);

        $webDto = VendorApplicationDTO::fromWebRequest($webRequest);
        $webResult = $service->submit($webDto);

        $this->assertNotNull($webResult['vendor']);
        $this->assertNotNull($webResult['store']);
        $this->assertEquals('Adebayo', $webResult['vendor']->f_name);
        $this->assertEquals('adebayo@example.com', $webResult['vendor']->email);
        $this->assertNull($webResult['vendor']->status); // Under review
        $this->assertEquals(0, $webResult['store']->status); // Pending approval
        $this->assertEquals('commission', $webResult['store']->store_business_model);
        $this->assertEquals('Adebayo Groceries', $webResult['store']->name);

        // 2. WhatsApp submission with equivalent data
        $contact = WhatsAppContact::create([
            'whatsapp_id' => '2348033334444',
            'phone_number' => '+2348033334444',
            'display_name' => 'Kudirat Supermarket',
        ]);

        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'review',
            'current_step' => 'review_submit',
            'collected_data' => [
                'f_name' => 'Kudirat',
                'l_name' => 'Abiola',
                'phone' => '+2348033334444',
                'email' => 'kudirat@example.com',
                'business_name' => 'Kudirat Supermarket',
                'address' => 'Bodija Market, Ibadan',
                'latitude' => 7.4215,
                'longitude' => 3.9059,
                'zone_id' => $zone->id,
                'module_id' => $module->id,
                'password_hash' => bcrypt('SecurePass123!'),
                'business_plan' => 'commission-base',
                'delivery_time' => '20-40 min',
                'terms_accepted' => true,
                'privacy_accepted' => true,
            ],
        ]);

        $waDto = VendorApplicationDTO::fromWhatsAppSession($session, $contact);
        $waResult = $service->submit($waDto);

        $this->assertNotNull($waResult['vendor']);
        $this->assertNotNull($waResult['store']);
        $this->assertEquals('Kudirat', $waResult['vendor']->f_name);
        $this->assertEquals('kudirat@example.com', $waResult['vendor']->email);
        $this->assertNull($waResult['vendor']->status); // Under review
        $this->assertEquals(0, $waResult['store']->status); // Pending approval
        $this->assertEquals('commission', $waResult['store']->store_business_model);
        $this->assertEquals('Kudirat Supermarket', $waResult['store']->name);
    }

    public function test_duplicate_phone_or_email_fails_cleanly(): void
    {
        $zone = Zone::create([
            'name' => 'Ibadan Zone 2',
            'coordinates' => null,
            'status' => 1,
            'restaurant_wise_topic' => 'zone_2',
            'customer_wise_topic' => 'cust_2',
            'deliveryman_wise_topic' => 'dm_2',
            'cash_on_delivery' => true,
            'digital_payment' => true,
        ]);

        $module = Module::create([
            'module_name' => 'Pharmacy Ibadan',
            'module_type' => 'pharmacy',
            'thumbnail' => 'pharmacy.png',
            'status' => 1,
            'stores_count' => 0,
            'all_zone_service' => 0,
        ]);

        \Illuminate\Support\Facades\DB::table('module_zone')->insert([
            'module_id' => $module->id,
            'zone_id' => $zone->id,
        ]);

        $service = app(VendorApplicationService::class);

        // Pre-create an approved vendor and store
        $vendor = Vendor::create([
            'f_name' => 'Existing',
            'l_name' => 'Vendor',
            'phone' => '2348099998888',
            'email' => 'existing@example.com',
            'password' => bcrypt('password'),
            'status' => 1,
        ]);
        Store::create([
            'name' => 'Existing Pharmacy',
            'phone' => '2348099998888',
            'email' => 'existing@example.com',
            'address' => 'Ibadan Central',
            'latitude' => 7.3775,
            'longitude' => 3.9470,
            'vendor_id' => $vendor->id,
            'zone_id' => $zone->id,
            'module_id' => $module->id,
            'status' => 1,
            'store_business_model' => 'commission',
        ]);

        $contact = WhatsAppContact::create([
            'whatsapp_id' => '2348099998888',
            'phone_number' => '2348099998888',
            'display_name' => 'Duplicate Attempt',
        ]);

        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'review',
            'current_step' => 'review_submit',
            'collected_data' => [
                'f_name' => 'New',
                'l_name' => 'Owner',
                'phone' => '2348099998888',
                'email' => 'different@example.com',
                'business_name' => 'Duplicate Attempt',
                'address' => 'Ibadan',
                'latitude' => 7.3775,
                'longitude' => 3.9470,
                'zone_id' => $zone->id,
                'module_id' => $module->id,
                'password_hash' => bcrypt('SecurePass123!'),
                'business_plan' => 'commission-base',
                'terms_accepted' => true,
                'privacy_accepted' => true,
            ],
        ]);

        $waDto = VendorApplicationDTO::fromWhatsAppSession($session, $contact);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->submit($waDto);
    }
}
