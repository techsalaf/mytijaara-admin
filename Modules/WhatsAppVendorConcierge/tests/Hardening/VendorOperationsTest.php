<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use App\Models\Category;
use App\Models\Item;
use App\Models\Module;
use App\Models\Order;
use App\Models\Store;
use App\Models\Vendor;
use App\Models\Zone;
use Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\OrderMutationService;
use Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\ProductMutationService;
use Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\StoreAvailabilityService;
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

class VendorOperationsTest extends OperationsFixtureTestCase
{
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

    public function test_price_change_cannot_make_discount_exceed_price(): void
    {
        $item = Item::forceCreate(['name' => 'Test item', 'price' => 100, 'discount' => 80, 'discount_type' => 'amount', 'store_id' => $this->store->id, 'module_id' => $this->module->id]);
        try { app(ProductMutationService::class)->updatePrice($item, $this->vendor->id, 50); $this->fail('Expected discount validation'); }
        catch (ValidationException) { $this->assertEquals(100, $item->fresh()->price); }
    }

    public function test_variant_stock_is_explicit_and_preserves_prices_and_addons(): void
    {
        $item = Item::forceCreate(['name' => 'Variants', 'price' => 100, 'stock' => 5,
            'variations' => json_encode([['type' => 'small', 'price' => 100, 'stock' => 2], ['type' => 'large', 'price' => 150, 'stock' => 3]]),
            'add_ons' => '[7]', 'store_id' => $this->store->id, 'module_id' => $this->module->id]);
        $service = app(ProductMutationService::class);
        try { $service->updateStock($item, $this->vendor->id, 10); $this->fail('Expected variation validation'); }
        catch (ValidationException) { $this->assertEquals(5, $item->fresh()->stock); }
        $updated = $service->updateStock($item, $this->vendor->id, 10, ['small' => 4, 'large' => 6]);
        $this->assertSame(10, array_sum(array_column(json_decode($updated->variations, true), 'stock')));
        $this->assertSame([100, 150], array_column(json_decode($updated->variations, true), 'price'));
        $this->assertSame('[7]', $updated->add_ons);
    }

    public function test_stale_price_preview_cannot_overwrite_a_newer_change(): void
    {
        $item = Item::forceCreate(['name' => 'Test item', 'price' => 100, 'store_id' => $this->store->id, 'module_id' => $this->module->id]);
        $actions = app(PendingActionService::class);
        $action = $actions->prepareProductPrice($this->contact->id, $this->conversation->id, $item->id, 120);
        $item->update(['price' => 150]);
        $actions->confirm($action->action_token, $this->contact->id, $this->conversation->id);
        $this->assertSame('cancelled', $action->fresh()->status);
        $this->assertEquals(150, $item->fresh()->price);
    }

    public function test_order_cancellation_respects_host_setting(): void
    {
        config(['canceled_by_store' => false]);
        $order = Order::forceCreate(['order_status' => 'pending', 'store_id' => $this->store->id, 'module_id' => $this->module->id]);
        try { app(OrderMutationService::class)->transitionStatus($order, $this->vendor->id, 'canceled', ['reason' => 'Unavailable']); $this->fail('Expected cancellation restriction'); }
        catch (ValidationException) { $this->assertSame('pending', $order->fresh()->order_status); }
    }

    public function test_product_moderation_stages_creation_and_price_without_publishing_changes(): void
    {
        config(['product_approval_conf' => ['value' => '1']]);
        Schema::create('ecommerce_item_details', function (Blueprint $t) {
            $t->id(); $t->integer('item_id')->nullable(); $t->integer('temp_product_id')->nullable(); $t->integer('brand_id')->nullable();
        });
        Schema::create('taxables', function (Blueprint $t) {
            $t->id(); $t->string('taxable_type'); $t->integer('taxable_id');
            $t->integer('system_tax_setup_id'); $t->integer('tax_id'); $t->timestamps();
        });
        foreach (['item_tag' => 'tag_id', 'item_nutrition' => 'nutrition_id', 'allergy_item' => 'allergy_id', 'item_generic_names' => 'generic_name_id'] as $table => $foreign) {
            Schema::create($table, function (Blueprint $t) use ($foreign) {
                $t->unsignedBigInteger('item_id'); $t->unsignedBigInteger($foreign);
            });
        }
        Schema::table('items', function (Blueprint $t) { $t->boolean('is_approved')->default(true); $t->text('images')->nullable(); });
        $definition = DB::table('sqlite_master')->where('name', 'items')->value('sql');
        DB::statement(str_replace('"items"', '"temp_products"', $definition));
        Schema::table('temp_products', function (Blueprint $t) {
            $t->integer('item_id'); $t->boolean('is_rejected')->default(false);
            foreach (['tag_ids', 'nutrition_ids', 'allergy_ids', 'generic_ids'] as $field) $t->text($field)->nullable();
        });
        DB::table('business_settings')->insert([
            ['key' => 'product_approval', 'value' => '1'],
            ['key' => 'product_approval_datas', 'value' => json_encode(['Add_new_product' => 1, 'Update_product_price' => 1])],
        ]);
        $service = app(ProductMutationService::class);
        $item = $service->createProduct($this->store, $this->vendor->id, ['name' => 'Review me', 'price' => 100, 'category_id' => $this->category->id]);
        $this->assertEquals(0, $item->is_approved);
        $this->assertTrue($item->relationLoaded('conciergeReview'));
        $draft = $item->getRelation('conciergeReview');
        $draft->name = 'Another pending name';
        $draft->tag_ids = '[17]';
        $draft->save();
        $result = $service->updatePrice($item, $this->vendor->id, 150);
        $this->assertTrue($result->relationLoaded('conciergeReview'));
        $this->assertEquals(100, $item->fresh()->price);
        $this->assertDatabaseHas('temp_products', ['item_id' => $item->id, 'price' => 150]);
        $this->assertDatabaseHas('temp_products', ['item_id' => $item->id, 'name' => 'Another pending name', 'tag_ids' => '[17]']);
        $this->assertDatabaseHas('translations', ['translationable_type' => \App\Models\TempProduct::class,
            'translationable_id' => $draft->id, 'locale' => 'en', 'key' => 'name', 'value' => 'Review me']);

        $existing = Item::forceCreate(['name' => 'Existing product', 'price' => 100, 'store_id' => $this->store->id,
            'module_id' => $this->module->id, 'image' => 'existing.png',
            'images' => [['img' => 'gallery.png', 'storage' => 'public']]]);
        Storage::disk('public')->put('product/existing.png', 'existing image bytes');
        Storage::disk('public')->put('product/gallery.png', 'gallery image bytes');
        DB::table('item_tag')->insert(['item_id' => $existing->id, 'tag_id' => 71]);
        DB::table('ecommerce_item_details')->insert(['item_id' => $existing->id, 'brand_id' => 12]);
        DB::table('taxables')->insert(['taxable_type' => Item::class, 'taxable_id' => $existing->id, 'system_tax_setup_id' => 1, 'tax_id' => 9]);
        \App\Models\Translation::create(['translationable_type' => Item::class, 'translationable_id' => $existing->id,
            'locale' => 'fr', 'key' => 'name', 'value' => 'Produit existant']);
        $staged = $service->updatePrice($existing, $this->vendor->id, 125)->getRelation('conciergeReview');
        $this->assertSame('[71]', $staged->tag_ids);
        $this->assertDatabaseHas('ecommerce_item_details', ['temp_product_id' => $staged->id, 'item_id' => null, 'brand_id' => 12]);
        $this->assertDatabaseHas('taxables', ['taxable_type' => \App\Models\TempProduct::class, 'taxable_id' => $staged->id, 'tax_id' => 9]);
        $this->assertDatabaseHas('translations', ['translationable_type' => \App\Models\TempProduct::class,
            'translationable_id' => $staged->id, 'locale' => 'fr', 'value' => 'Produit existant']);
        $this->assertNotSame('existing.png', $staged->image);
        $this->assertNotSame('gallery.png', $staged->images[0]['img']);
        Storage::disk('public')->assertExists(['product/existing.png', 'product/gallery.png',
            'product/'.$staged->image, 'product/'.$staged->images[0]['img']]);
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
        \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage::create([
            'conversation_id' => $this->conversation->id, 'media_id' => $media->id,
            'direction' => 'inbound', 'type' => 'image', 'content' => ['image' => ['id' => 'media_prod_123']],
            'whatsapp_message_id' => 'photo-product-inbound',
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
        $item = Item::where('name', 'Yam Tubers (Medium)')->firstOrFail();
        $this->assertSame(basename($item->image), $item->image);
        $this->assertNotSame($media->file_path, $item->image);
        Storage::disk(\App\CentralLogics\Helpers::getDisk())->assertExists('product/'.$item->image);
        Storage::disk('public')->assertExists($media->file_path);
    }

    public function test_product_media_cannot_be_claimed_without_an_owned_inbound_message(): void
    {
        $media = WhatsAppMedia::create(['whatsapp_media_id' => 'another-vendor-image', 'mime_type' => 'image/png',
            'status' => 'processed', 'file_path' => 'private/another-vendor.png', 'storage_disk' => 'local']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\ProductMedia::class)->owned($media->id, $this->vendor->id);
    }
}
