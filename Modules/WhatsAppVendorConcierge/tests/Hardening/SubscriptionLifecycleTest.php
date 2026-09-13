<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use App\Models\BusinessSetting;
use App\Models\Store;
use App\Models\SubscriptionPackage;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Services\SubscriptionLifecycleService;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;

class SubscriptionLifecycleTest extends HardeningTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::dropIfExists('stores');
        Schema::dropIfExists('vendors');

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->tinyInteger('status')->nullable()->default(1);
            $table->timestamps();
        });

        Schema::create('subscription_packages', function (Blueprint $table) {
            $table->id();
            $table->string('package_name');
            $table->double('price');
            $table->integer('validity');
            $table->string('max_order')->default('unlimited');
            $table->string('max_product')->default('unlimited');
            $table->boolean('pos')->default(false);
            $table->boolean('mobile_app')->default(false);
            $table->boolean('chat')->default(false);
            $table->boolean('review')->default(false);
            $table->boolean('self_delivery')->default(false);
            $table->tinyInteger('status')->default(1);
            $table->string('module_type')->default('all');
            $table->timestamps();
        });

        Schema::create('store_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('package_id');
            $table->unsignedBigInteger('store_id');
            $table->date('expiry_date');
            $table->string('max_order')->default('unlimited');
            $table->string('max_product')->default('unlimited');
            $table->boolean('pos')->default(false);
            $table->boolean('mobile_app')->default(false);
            $table->boolean('chat')->default(false);
            $table->boolean('review')->default(false);
            $table->boolean('self_delivery')->default(false);
            $table->tinyInteger('status')->default(1);
            $table->integer('total_package_renewed')->default(0);
            $table->integer('validity');
            $table->boolean('is_trial')->default(false);
            $table->boolean('is_canceled')->default(false);
            $table->string('canceled_by')->default('none');
            $table->timestamp('renewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->string('store_business_model')->default('none');
            $table->tinyInteger('item_section')->default(0);
            $table->tinyInteger('pos_system')->default(0);
            $table->tinyInteger('reviews_section')->default(0);
            $table->tinyInteger('self_delivery_system')->default(0);
            $table->tinyInteger('free_delivery')->default(0);
            $table->tinyInteger('status')->default(0);
            $table->timestamps();
        });

        Schema::create('subscription_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('package_id');
            $table->unsignedBigInteger('store_id');
            $table->double('price');
            $table->integer('validity');
            $table->double('paid_amount');
            $table->string('payment_status');
            $table->string('created_by');
            $table->string('payment_method');
            $table->string('reference')->nullable();
            $table->double('discount')->default(0);
            $table->string('plan_type')->nullable();
            $table->text('package_details')->nullable();
            $table->boolean('is_trial')->default(false);
            $table->unsignedBigInteger('store_subscription_id')->nullable();
            $table->tinyInteger('transaction_status')->default(1);
            $table->timestamps();
        });

        Schema::create('subscription_billing_and_refund_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('transaction_type');
            $table->tinyInteger('is_success')->default(0);
            $table->double('amount')->default(0);
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('created_by')->default('admin');
            $table->string('coupon_type')->default('default');
            $table->timestamps();
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('storages', function (Blueprint $table) {
            $table->id();
            $table->string('data_type');
            $table->string('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
    }

    public function test_payment_link_generation_and_pending_transition(): void
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => '2348012345678',
            'phone_number' => '2348012345678',
        ]);

        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'submitted',
            'collected_data' => [
                'business_name' => 'Test Store',
                'business_plan' => 'subscription-base',
                'package_id' => 1,
            ],
        ]);

        $gateway = $this->createMock(WhatsAppGateway::class);
        $lifecycle = new SubscriptionLifecycleService($gateway);

        // 1. Issue link
        $url = $lifecycle->issuePaymentLink($session, 7);
        $this->assertNotEmpty($url);
        $this->assertStringContainsString('signature=', $url);

        $session->refresh();
        $this->assertEquals(SubscriptionLifecycleService::STATE_LINK_ISSUED, $lifecycle->getPaymentState($session));

        // 2. Record pending when visited
        $lifecycle->recordPending($session);
        $session->refresh();
        $this->assertEquals(SubscriptionLifecycleService::STATE_PENDING, $lifecycle->getPaymentState($session));
    }

    public function test_successful_payment_callback_activates_subscription_and_notifies_vendor(): void
    {
        $vendor = Vendor::create(['id' => 50, 'f_name' => 'Bolanle', 'phone' => '2348022223333', 'status' => 1]);
        $package = SubscriptionPackage::create([
            'id' => 10,
            'package_name' => 'Standard Plan',
            'price' => 5000,
            'validity' => 30,
            'status' => 1,
        ]);
        $store = Store::create([
            'id' => 60,
            'name' => 'Bolanle Boutique',
            'vendor_id' => $vendor->id,
            'package_id' => $package->id,
            'store_business_model' => 'none',
        ]);

        $contact = WhatsAppContact::create([
            'whatsapp_id' => '2348022223333',
            'phone_number' => '2348022223333',
            'vendor_id' => $vendor->id,
        ]);

        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'vendor_id' => $vendor->id,
            'state' => 'onboarding_active',
        ]);

        // Inbound message inside 24h window
        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.sub.inbound',
            'direction' => 'inbound',
            'type' => 'text',
            'raw_text' => 'Ready for payment',
            'created_at' => now(),
        ]);

        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'store_id' => $store->id,
            'vendor_id' => $vendor->id,
            'status' => 'submitted',
            'collected_data' => [
                'business_name' => 'Bolanle Boutique',
                'package_id' => $package->id,
                'payment_state' => SubscriptionLifecycleService::STATE_PENDING,
            ],
        ]);

        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->once())
            ->method('sendTextMessage')
            ->with(
                '2348022223333',
                $this->callback(function ($msg) {
                    return str_contains($msg, 'Payment Confirmed') && str_contains($msg, 'Bolanle Boutique');
                })
            );

        $lifecycle = new SubscriptionLifecycleService($gateway);

        // First callback
        $result = $lifecycle->handlePaymentSuccess($store->id, 'paystack', 'ref_123456');
        $this->assertTrue($result['success']);
        $this->assertEquals(SubscriptionLifecycleService::STATE_SUCCEEDED, $result['state']);

        $session->refresh();
        $this->assertEquals(SubscriptionLifecycleService::STATE_SUCCEEDED, $lifecycle->getPaymentState($session));

        // Assert database subscription was created
        $this->assertDatabaseHas('store_subscriptions', [
            'store_id' => $store->id,
            'package_id' => $package->id,
            'status' => 1,
        ]);

        // Duplicate callback (replay) must be idempotent without error
        $result2 = $lifecycle->handlePaymentSuccess($store->id, 'paystack', 'ref_123456');
        $this->assertTrue($result2['success']);
    }

    public function test_expired_link_detection_and_regeneration(): void
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => '2348044445555',
            'phone_number' => '2348044445555',
        ]);

        $store = Store::create(['id' => 99, 'name' => 'Expired Store']);

        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'store_id' => $store->id,
            'status' => 'submitted',
            'collected_data' => [
                'business_name' => 'Expired Store',
                'payment_state' => SubscriptionLifecycleService::STATE_LINK_ISSUED,
                'payment_link_expires_at' => now()->subDay()->toIso8601String(), // Expired
            ],
        ]);

        $gateway = $this->createMock(WhatsAppGateway::class);
        $lifecycle = new SubscriptionLifecycleService($gateway);

        // Reconcile detects expiry
        $reconcile = $lifecycle->reconcilePayment($session);
        $this->assertEquals('expired', $reconcile['status']);

        $session->refresh();
        $this->assertEquals(SubscriptionLifecycleService::STATE_EXPIRED, $lifecycle->getPaymentState($session));

        // Regenerate link
        $newUrl = $lifecycle->issuePaymentLink($session, 7);
        $this->assertNotEmpty($newUrl);

        $session->refresh();
        $this->assertEquals(SubscriptionLifecycleService::STATE_LINK_ISSUED, $lifecycle->getPaymentState($session));
    }
}
