<?php

namespace Modules\WhatsAppVendorConcierge\tests\Unit;

use App\Models\Category;
use App\Models\Item;
use App\Models\Module;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Ai\Tools\Request as ToolRequest;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\CreateProductTool;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\PauseShopTool;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\ResumeShopTool;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\UpdateProductTool;
use Modules\WhatsAppVendorConcierge\app\Agents\VendorAiContext;
use Tests\TestCase;

class VendorToolsConfirmationTest extends TestCase
{
    use DatabaseTransactions;

    protected function createStoreWithVendor(): array
    {
        $vendor = Vendor::create([
            'f_name' => 'Tool',
            'l_name' => 'Tester',
            'email' => 'tool_' . uniqid() . '@test.com',
            'phone' => '234' . rand(8000000000, 8099999999),
            'password' => bcrypt('password123'),
            'status' => 1,
        ]);

        $module = Module::first();
        $moduleId = $module ? $module->id : 1;

        $store = Store::create([
            'name' => 'Tool Test Store',
            'phone' => $vendor->phone,
            'email' => $vendor->email,
            'latitude' => 9.0765,
            'longitude' => 7.3986,
            'address' => 'Abuja, Nigeria',
            'vendor_id' => $vendor->id,
            'zone_id' => 1,
            'module_id' => $moduleId,
            'status' => 1,
            'active' => true,
        ]);

        return [$vendor, $store];
    }

    /** @test */
    public function create_product_requires_confirmation_when_writes_guarded()
    {
        config(['whatsapp-vendor-concierge.security.require_verification_for_writes' => true]);

        [$vendor, $store] = $this->createStoreWithVendor();

        $category = Category::create([
            'name' => 'Test Category ' . uniqid(),
            'module_id' => $store->module_id,
            'status' => 1,
        ]);

        $context = new VendorAiContext();
        $tool = new CreateProductTool($context, $vendor, $store);

        // Call without confirm flag
        $requestNoConfirm = new ToolRequest([
            'name' => 'Jollof Rice Special',
            'price' => 3500,
            'category_id' => $category->id,
            'confirm' => false,
        ]);

        $response = $tool->handle($requestNoConfirm);
        $this->assertStringContainsString('Confirm New Product Listing', $response);
        $this->assertDatabaseMissing('items', [
            'name' => 'Jollof Rice Special',
            'store_id' => $store->id,
        ]);

        // Call with confirm flag
        $requestWithConfirm = new ToolRequest([
            'name' => 'Jollof Rice Special',
            'price' => 3500,
            'category_id' => $category->id,
            'confirm' => true,
        ]);

        $confirmResponse = $tool->handle($requestWithConfirm);
        $this->assertStringContainsString('Product Created Successfully', $confirmResponse);
        $this->assertDatabaseHas('items', [
            'name' => 'Jollof Rice Special',
            'store_id' => $store->id,
        ]);
    }

    /** @test */
    public function update_product_requires_confirmation_before_mutating()
    {
        config(['whatsapp-vendor-concierge.security.require_verification_for_writes' => true]);

        [$vendor, $store] = $this->createStoreWithVendor();

        $item = Item::create([
            'name' => 'Original Shawarma',
            'price' => 2000,
            'store_id' => $store->id,
            'module_id' => $store->module_id,
            'status' => 1,
        ]);

        $context = new VendorAiContext();
        $tool = new UpdateProductTool($context, $vendor, $store);

        // Call without confirm
        $reqNoConfirm = new ToolRequest([
            'product_id' => $item->id,
            'price' => 3000,
            'confirm' => false,
        ]);

        $preview = $tool->handle($reqNoConfirm);
        $this->assertStringContainsString('Confirm Product Update', $preview);
        $this->assertStringContainsString('currently ₦2,000 → change to **₦3,000**', $preview);

        $item->refresh();
        $this->assertEquals(2000, $item->price);

        // Call with confirm
        $reqWithConfirm = new ToolRequest([
            'product_id' => $item->id,
            'price' => 3000,
            'confirm' => true,
        ]);

        $success = $tool->handle($reqWithConfirm);
        $this->assertStringContainsString('Product Updated Successfully', $success);

        $item->refresh();
        $this->assertEquals(3000, $item->price);
    }

    /** @test */
    public function pause_and_resume_shop_require_confirmation()
    {
        [$vendor, $store] = $this->createStoreWithVendor();
        $store->update(['active' => true]);

        $context = new VendorAiContext();

        // Test pause
        $pauseTool = new PauseShopTool($context, $vendor, $store);
        $pausePrompt = $pauseTool->handle(new ToolRequest(['confirm' => false]));
        $this->assertStringContainsString('Confirm Shop Pause', $pausePrompt);

        $pauseTool->handle(new ToolRequest(['confirm' => true]));
        $store->refresh();
        $this->assertFalse((bool) $store->active);

        // Test resume
        $resumeTool = new ResumeShopTool($context, $vendor, $store);
        $resumePrompt = $resumeTool->handle(new ToolRequest(['confirm' => false]));
        $this->assertStringContainsString('Confirm Shop Resume', $resumePrompt);

        $resumeTool->handle(new ToolRequest(['confirm' => true]));
        $store->refresh();
        $this->assertTrue((bool) $store->active);
    }
}
