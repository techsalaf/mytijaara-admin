<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use App\Models\Category;
use App\Models\Item;
use App\Models\Module;
use App\Models\Order;
use App\Models\Store;
use App\Models\Vendor;
use App\Models\Zone;
use App\Services\OrderMutationService;
use App\Services\ProductMutationService;
use App\Services\StoreAvailabilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\WhatsAppVendorConcierge\app\Models\PendingAction;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia;
use Modules\WhatsAppVendorConcierge\app\Services\MediaPolicyService;
use Modules\WhatsAppVendorConcierge\app\Services\PendingActionService;
use Modules\WhatsAppVendorConcierge\app\Services\PhotoToProductService;

class VendorOperationsTest extends HardeningTestCase
{
    protected Vendor $vendor;
    protected Store $store;
    protected Zone $zone;
    protected Module $module;
    protected Category $category;
    protected WhatsAppContact $contact;
    protected WhatsAppConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        // Drop minimal mock tables and create realistic ones
        Schema::dropIfExists('stores');
        Schema::dropIfExists('vendors');

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->unique();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('logo')->default('def.png');
            $table->string('cover_photo')->default('def.png');
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->text('address')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->unsignedBigInteger('module_id')->nullable();
            $table->string('store_business_model')->default('commission');
            $table->string('delivery_time')->default('30-40 min');
            $table->boolean('sub_self_delivery')->default(false);
            $table->integer('order_count')->default(0);
            $table->timestamps();
        });

        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('module_name');
            $table->string('module_type')->default('grocery');
            $table->string('slug')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('image')->default('def.png');
            $table->integer('parent_id')->default(0);
            $table->integer('position')->default(0);
            $table->tinyInteger('status')->default(1);
            $table->unsignedBigInteger('module_id')->nullable();
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('category_ids')->nullable();
            $table->text('variations')->nullable();
            $table->text('add_ons')->nullable();
            $table->text('attributes')->nullable();
            $table->text('choice_options')->nullable();
            $table->double('price', 24, 2)->default(0);
            $table->double('tax', 24, 2)->default(0);
            $table->string('tax_type', 20)->default('percent');
            $table->double('discount', 24, 2)->default(0);
            $table->string('discount_type', 20)->default('percent');
            $table->time('available_time_starts')->default('00:00:00');
            $table->time('available_time_ends')->default('23:59:59');
            $table->boolean('veg')->default(0);
            $table->boolean('status')->default(1);
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('module_id');
            $table->integer('stock')->default(0);
            $table->integer('order_count')->default(0);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->double('order_amount', 24, 2)->default(0);
            $table->string('order_status')->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->string('payment_method')->default('cash_on_delivery');
            $table->string('order_type')->default('delivery');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('module_id')->nullable();
            $table->timestamp('confirmed')->nullable();
            $table->timestamp('processing')->nullable();
            $table->timestamp('handover')->nullable();
            $table->timestamp('delivered')->nullable();
            $table->timestamp('canceled')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->string('canceled_by')->nullable();
            $table->integer('processing_time')->nullable();
            $table->boolean('is_guest')->default(0);
            $table->timestamps();
        });

        Schema::create('order_references', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->timestamps();
        });

        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('key');
            $table->boolean('push_notification_status')->default(0);
            $table->timestamps();
        });

        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->double('price', 24, 2)->default(0);
            $table->integer('quantity')->default(1);
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

        // Seed basic operational fixtures
        $this->vendor = Vendor::create([
            'f_name' => 'Alabi',
            'l_name' => 'Fasola',
            'phone' => '+2348077771111',
            'email' => 'alabi@example.com',
            'status' => 1,
        ]);

        $this->zone = Zone::create(['name' => 'Ibadan Zone', 'status' => 1]);
        $this->module = Module::create(['module_name' => 'Grocery', 'module_type' => 'grocery', 'status' => 1]);

        $this->store = Store::create([
            'name' => 'Fasola Provisions Ibadan',
            'phone' => '+2348077771111',
            'email' => 'alabi@example.com',
            'vendor_id' => $this->vendor->id,
            'zone_id' => $this->zone->id,
            'module_id' => $this->module->id,
            'status' => 1,
            'active' => 1,
        ]);

        $this->category = Category::create([
            'name' => 'Grains & Staples',
            'module_id' => $this->module->id,
            'status' => 1,
        ]);

        $this->contact = WhatsAppContact::create([
            'whatsapp_id' => '2348077771111',
            'phone_number' => '+2348077771111',
            'display_name' => 'Alabi Fasola',
            'vendor_id' => $this->vendor->id,
            'contact_type' => 'vendor',
        ]);

        $this->conversation = WhatsAppConversation::create([
            'contact_id' => $this->contact->id,
            'vendor_id' => $this->vendor->id,
            'state' => 'ai_active',
        ]);
    }

    public function test_product_mutation_service_creates_product_with_canonical_rules(): void
    {
        $service = app(ProductMutationService::class);

        $item = $service->createProduct($this->store, $this->vendor->id, [
            'name' => 'Bag of Ofada Rice (5kg)',
            'price' => 12500.00,
            'category_id' => $this->category->id,
            'stock' => 25,
            'description' => 'Locally grown premium Ofada rice',
        ]);

        $this->assertNotNull($item->id);
        $this->assertEquals('Bag of Ofada Rice (5kg)', $item->name);
        $this->assertEquals(12500.00, $item->price);
        $this->assertEquals(25, $item->stock);
        $this->assertEquals(1, $item->status);
        $this->assertEquals($this->store->id, $item->store_id);

        $this->assertDatabaseHas('translations', [
            'translationable_type' => Item::class,
            'translationable_id' => $item->id,
            'key' => 'name',
            'value' => 'Bag of Ofada Rice (5kg)',
        ]);
    }

    public function test_product_mutation_service_rejects_unauthorized_store(): void
    {
        $otherVendor = Vendor::create(['phone' => '2348099990000', 'email' => 'other@example.com']);
        $service = app(ProductMutationService::class);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->createProduct($this->store, $otherVendor->id, [
            'name' => 'Hack Attempt',
            'price' => 500,
            'category_id' => $this->category->id,
        ]);
    }

    public function test_pending_action_updates_product_price_with_explicit_confirmation(): void
    {
        $item = Item::create([
            'name' => 'Palm Oil 2L',
            'price' => 4500.00,
            'category_id' => $this->category->id,
            'store_id' => $this->store->id,
            'module_id' => $this->module->id,
            'stock' => 10,
            'status' => 1,
        ]);

        $pendingService = app(PendingActionService::class);

        // 1. Prepare Price Update
        $action = $pendingService->prepareProductPrice(
            $this->contact->id,
            $this->conversation->id,
            $item->id,
            5200.00
        );

        $this->assertEquals('pending', $action->status);
        $this->assertEquals('product_price_update', $action->action_type);
        $this->assertStringContainsString('5,200.00', $action->preview);

        // Verify item price did NOT change yet
        $this->assertEquals(4500.00, $item->fresh()->price);

        // 2. Confirm the pending action
        $result = $pendingService->confirm($action->action_token, $this->contact->id, $this->conversation->id);

        $this->assertStringContainsString('updated to ₦5,200.00', $result);
        $this->assertEquals('executed', $action->fresh()->status);
        $this->assertEquals(5200.00, $item->fresh()->price);

        // 3. Replay protection
        $replayResult = $pendingService->confirm($action->action_token, $this->contact->id, $this->conversation->id);
        $this->assertEquals('This action is unavailable or already completed.', $replayResult);
    }

    public function test_pending_action_updates_product_stock_and_availability(): void
    {
        $item = Item::create([
            'name' => 'Ijebu Garri (1kg)',
            'price' => 1200.00,
            'category_id' => $this->category->id,
            'store_id' => $this->store->id,
            'module_id' => $this->module->id,
            'stock' => 5,
            'status' => 1,
        ]);

        $pendingService = app(PendingActionService::class);

        // Update Stock
        $action = $pendingService->prepareProductStock(
            $this->contact->id,
            $this->conversation->id,
            $item->id,
            40
        );
        $pendingService->confirm($action->action_token, $this->contact->id, $this->conversation->id);
        $this->assertEquals(40, $item->fresh()->stock);

        // Toggle Availability
        $toggleAction = $pendingService->prepareProductAvailability(
            $this->contact->id,
            $this->conversation->id,
            $item->id,
            false
        );
        $pendingService->confirm($toggleAction->action_token, $this->contact->id, $this->conversation->id);
        $this->assertEquals(0, $item->fresh()->status);
    }

    public function test_order_mutation_service_and_pending_action_accept_and_prepare_order(): void
    {
        $order = new Order();
        $order->order_amount = 8500.00;
        $order->order_status = 'pending';
        $order->store_id = $this->store->id;
        $order->module_id = $this->module->id;
        $order->save();

        $pendingService = app(PendingActionService::class);

        // 1. Accept / Confirm Order
        $confirmAction = $pendingService->prepareOrderStatus(
            $this->contact->id,
            $this->conversation->id,
            $order->id,
            'confirmed'
        );

        $this->assertStringContainsString("Accept & Confirm Order #{$order->id}", $confirmAction->preview);

        $result = $pendingService->confirm($confirmAction->action_token, $this->contact->id, $this->conversation->id);
        $this->assertStringContainsString('confirmed', $result);

        $freshOrder = $order->fresh();
        $this->assertEquals('confirmed', $freshOrder->order_status);
        $this->assertNotNull($freshOrder->confirmed);

        // 2. Start Preparation
        $prepareAction = $pendingService->prepareOrderStatus(
            $this->contact->id,
            $this->conversation->id,
            $order->id,
            'processing'
        );
        $pendingService->confirm($prepareAction->action_token, $this->contact->id, $this->conversation->id);

        $this->assertEquals('processing', $order->fresh()->order_status);
        $this->assertNotNull($order->fresh()->processing);
    }

    public function test_order_mutation_service_rejects_invalid_transitions(): void
    {
        $order = new Order();
        $order->order_amount = 5000.00;
        $order->order_status = 'delivered';
        $order->delivered = now();
        $order->store_id = $this->store->id;
        $order->module_id = $this->module->id;
        $order->save();

        $orderService = app(OrderMutationService::class);

        // Cannot modify status after delivered
        $this->expectException(ValidationException::class);
        $orderService->transitionStatus($order, $this->vendor->id, 'processing');
    }

    public function test_photo_to_product_workflow(): void
    {
        // Create realistic test JPEG
        $img = imagecreatetruecolor(400, 400);
        ob_start();
        imagejpeg($img);
        $jpegBytes = ob_get_clean();
        imagedestroy($img);

        Storage::disk('public')->put('products/sample_product.jpg', $jpegBytes);

        $media = WhatsAppMedia::create([
            'whatsapp_media_id' => 'media_prod_123',
            'mime_type' => 'image/jpeg',
            'file_size' => strlen($jpegBytes),
            'storage_disk' => 'public',
            'file_path' => 'products/sample_product.jpg',
            'status' => 'downloaded',
        ]);

        $photoService = app(PhotoToProductService::class);

        // Step 1: Ingest media with AI suggestion
        $startResult = $photoService->startDraftFromMedia(
            $this->contact,
            $this->conversation,
            $media,
            [
                'name' => 'Yam Tubers (Medium)',
                'category_id' => $this->category->id,
                'category_name' => $this->category->name,
                'description' => 'Fresh harvest yam tubers',
            ]
        );

        $this->assertTrue($startResult['success']);
        $this->assertStringContainsString('Yam Tubers (Medium)', $startResult['prompt']);

        // Step 2: Vendor provides price and confirms details
        $context = $this->conversation->fresh()->context;
        $context['photo_to_product_draft']['price'] = 3500.00;
        $this->conversation->update(['context' => $context]);

        // Step 3: Prepare Confirmation PendingAction
        $action = $photoService->prepareConfirmation($this->contact, $this->conversation, $this->store);

        $this->assertEquals('product_create', $action->action_type);
        $this->assertStringContainsString('Yam Tubers (Medium)', $action->preview);
        $this->assertStringContainsString('3,500.00', $action->preview);

        // Step 4: Execute Confirmation
        $pendingService = app(PendingActionService::class);
        $confirmResult = $pendingService->confirm($action->action_token, $this->contact->id, $this->conversation->id);

        $this->assertStringContainsString('successfully added to your shop catalog', $confirmResult);
        $this->assertDatabaseHas('items', [
            'name' => 'Yam Tubers (Medium)',
            'price' => 3500.00,
            'store_id' => $this->store->id,
        ]);
    }
}
