<?php
namespace Modules\WhatsAppVendorConcierge\tests\Unit;

use App\Models\Item;
use Laravel\Ai\Tools\Request as ToolRequest;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\CreateProductTool;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\PauseShopTool;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\ResumeShopTool;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\UpdateProductTool;
use Modules\WhatsAppVendorConcierge\app\Agents\VendorAiContext;
use Modules\WhatsAppVendorConcierge\app\Models\PendingAction;
use Modules\WhatsAppVendorConcierge\app\Services\PendingActionService;
use Modules\WhatsAppVendorConcierge\tests\Hardening\OperationsFixtureTestCase;

class VendorToolsConfirmationTest extends OperationsFixtureTestCase
{
    private function context(): VendorAiContext
    {
        return new VendorAiContext($this->contact->id, $this->conversation->id);
    }

    private function confirmLatest(): PendingAction
    {
        $action = PendingAction::latest('id')->firstOrFail();
        app(PendingActionService::class)->confirm($action->action_token, $this->contact->id, $this->conversation->id);
        $this->assertSame('executed', $action->fresh()->status);
        return $action;
    }

    public function test_ai_confirm_flag_cannot_create_product_without_vendor_confirmation(): void
    {
        $tool = new CreateProductTool($this->context(), $this->vendor, $this->store);
        $result = $tool->handle(new ToolRequest(['name' => 'Rice', 'price' => 3500,
            'category_id' => $this->category->id, 'confirm' => true]));
        $this->assertStringContainsString('confirmation preview', $result);
        $this->assertDatabaseMissing('items', ['name' => 'Rice']);
        $action = $this->confirmLatest();
        $this->assertDatabaseHas('items', ['name' => 'Rice', 'stock' => 0, 'store_id' => $this->store->id]);
        app(PendingActionService::class)->confirm($action->action_token, $this->contact->id, $this->conversation->id);
        $this->assertSame(1, Item::where('name', 'Rice')->count());
    }

    public function test_price_preview_requires_vendor_confirmation(): void
    {
        $item = Item::forceCreate(['name' => 'Rice', 'price' => 2000, 'store_id' => $this->store->id,
            'module_id' => $this->module->id]);
        $tool = new UpdateProductTool($this->context(), $this->vendor, $this->store);
        $result = $tool->handle(new ToolRequest(['product_id' => $item->id, 'price' => 3000, 'confirm' => true]));
        $this->assertStringContainsString('confirmation preview', $result);
        $this->assertEquals(2000, $item->fresh()->price);
        $this->confirmLatest();
        $this->assertEquals(3000, $item->fresh()->price);
    }

    public function test_pause_and_resume_require_separate_confirmations(): void
    {
        $pause = new PauseShopTool($this->context(), $this->vendor, $this->store);
        $pause->handle(new ToolRequest(['confirm' => true]));
        $this->assertTrue((bool) $this->store->fresh()->active);
        $this->confirmLatest();
        $this->assertFalse((bool) $this->store->fresh()->active);
        $resume = new ResumeShopTool($this->context(), $this->vendor, $this->store->fresh());
        $resume->handle(new ToolRequest(['confirm' => true]));
        $this->assertFalse((bool) $this->store->fresh()->active);
        $this->confirmLatest();
        $this->assertTrue((bool) $this->store->fresh()->active);
    }

    public function test_missing_conversation_cannot_prepare_a_mutation(): void
    {
        $tool = new PauseShopTool(new VendorAiContext(), $this->vendor, $this->store);
        $this->assertStringContainsString('active WhatsApp conversation', $tool->handle(new ToolRequest(['confirm' => true])));
        $this->assertSame(0, PendingAction::count());
        $this->assertTrue((bool) $this->store->fresh()->active);
    }
}
