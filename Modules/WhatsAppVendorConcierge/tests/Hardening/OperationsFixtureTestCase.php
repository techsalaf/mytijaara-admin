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

abstract class OperationsFixtureTestCase extends HardeningTestCase
{
    protected Vendor $vendor;
    protected Store $store;
    protected Zone $zone;
    protected Module $module;
    protected Category $category;
    protected WhatsAppContact $contact;
    protected WhatsAppConversation $conversation;
    private ?int $productMediaId = null;

    protected function productData(array $overrides): array
    {
        if (!$this->productMediaId) {
            $image = imagecreatetruecolor(400, 400);
            ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
            Storage::disk('public')->put('fixture/product.png', $bytes);
            $media = WhatsAppMedia::create(['whatsapp_media_id' => 'fixture-'.bin2hex(random_bytes(8)),
                'mime_type' => 'image/png', 'file_size' => strlen($bytes), 'storage_disk' => 'public',
                'file_path' => 'fixture/product.png', 'status' => 'downloaded']);
            \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage::create([
                'conversation_id' => $this->conversation->id, 'media_id' => $media->id, 'direction' => 'inbound',
                'type' => 'image', 'content' => [], 'whatsapp_message_id' => 'fixture-image-'.$media->id]);
            $this->productMediaId = $media->id;
        }
        return array_replace(['description' => 'Fixture product description', 'image' => (string) $this->productMediaId, 'stock' => 0], $overrides);
    }

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
            $table->unsignedBigInteger('store_category_id')->nullable();
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

        Schema::create('store_categories', function (Blueprint $t) {
            $t->id(); $t->integer('store_id'); $t->string('name');
        });
        Schema::create('ecommerce_item_details', function (Blueprint $t) {
            $t->id(); $t->integer('item_id')->nullable(); $t->integer('temp_product_id')->nullable();
            $t->integer('brand_id')->nullable(); $t->timestamps();
        });
        Schema::create('pharmacy_item_details', function (Blueprint $t) {
            $t->id(); $t->integer('item_id')->nullable(); $t->integer('temp_product_id')->nullable();
            $t->integer('common_condition_id')->nullable(); $t->boolean('is_basic')->default(false);
            $t->boolean('is_prescription_required')->default(false); $t->string('unit_value')->nullable();
            $t->string('manufacturer')->nullable(); $t->timestamps();
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

        $this->category = Category::forceCreate([
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

}
