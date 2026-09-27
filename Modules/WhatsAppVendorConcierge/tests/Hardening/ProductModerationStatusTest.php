<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\WhatsAppVendorConcierge\app\Services\ProductModerationStatusService;

class ProductModerationStatusTest extends HardeningTestCase
{
    public function test_status_is_store_scoped_and_separates_pending_edits_from_approval(): void
    {
        Schema::dropIfExists('items');
        Schema::dropIfExists('temp_products');
        Schema::create('items', function (Blueprint $t) {
            $t->id(); $t->integer('store_id'); $t->string('name');
            $t->boolean('is_approved'); $t->boolean('status'); $t->timestamps();
        });
        Schema::create('temp_products', function (Blueprint $t) {
            $t->id(); $t->integer('store_id'); $t->integer('item_id');
            $t->boolean('is_rejected'); $t->text('note')->nullable(); $t->timestamps();
        });
        DB::table('items')->insert([
            ['id'=>1,'store_id'=>4,'name'=>'Bag','is_approved'=>1,'status'=>1,'updated_at'=>now()],
            ['id'=>2,'store_id'=>5,'name'=>'Private product','is_approved'=>0,'status'=>1,'updated_at'=>now()],
        ]);
        $service = app(ProductModerationStatusService::class);
        $this->assertSame([], $service->products(4, 2));
        $this->assertSame('Approved', $service->products(4, 1)[0]['status']);
        DB::table('temp_products')->insert(['id'=>8,'store_id'=>4,'item_id'=>1,'is_rejected'=>0,'updated_at'=>now()]);
        $product = $service->products(4, 1)[0];
        $this->assertSame('Under review', $product['status']);
        $this->assertStringContainsString('existing version remains approved', $product['message']);
        $this->assertSame(['id'=>8,'temp_product'=>1], $product['edit_parameters']);
        DB::table('temp_products')->where('id', 8)->update(['is_rejected'=>1,'note'=>'<b>Please replace the photo</b>']);
        $product = $service->products(4, 1)[0];
        $this->assertSame('Needs correction', $product['status']);
        $this->assertStringContainsString('Please replace the photo', $product['message']);
        $this->assertStringNotContainsString('<b>', $product['message']);
        $this->assertCount(1, $service->products(4));
    }
}
