<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use App\Models\Item;
use Illuminate\Support\Facades\Queue;
use Modules\WhatsAppVendorConcierge\app\Models\PendingAction;
use Modules\WhatsAppVendorConcierge\app\Services\{ShopQuickActionService, WhatsAppGateway};

class ShopQuickActionTest extends OperationsFixtureTestCase
{
    public function test_explicit_commands_prepare_confirmation_without_mutating_the_product(): void
    {
        Queue::fake();
        $item = Item::forceCreate(['name'=>'Bag','price'=>100,'stock'=>5,'store_id'=>$this->store->id,'module_id'=>$this->module->id]);
        $service = app(ShopQuickActionService::class);
        $gateway = \Mockery::mock(WhatsAppGateway::class);
        $this->assertTrue($service->handle("Set price #{$item->id} 13000", $this->conversation, $this->contact, $gateway));
        $this->assertSame(100.0, (float) $item->fresh()->price);
        $action = PendingAction::latest('id')->firstOrFail();
        $this->assertSame('product_price_update', $action->action_type);
        $this->assertSame('pending', $action->status);
        $this->assertSame(13000.0, (float) $action->payload['new_price']);
        config(['module.'.$this->module->module_type.'.stock'=>true]);
        $this->assertTrue($service->handle("Set stock #{$item->id} 0", $this->conversation, $this->contact, $gateway));
        $this->assertSame(5, (int) $item->fresh()->stock);
        $this->assertSame(0, PendingAction::latest('id')->first()->payload['new_stock']);
        Queue::assertPushed(\Modules\WhatsAppVendorConcierge\app\Jobs\SendWhatsAppMessage::class, 2);
    }

    public function test_foreign_products_malformed_amounts_and_variations_cannot_prepare_updates(): void
    {
        Queue::fake();
        $foreign = Item::forceCreate(['name'=>'Private','price'=>100,'store_id'=>999,'module_id'=>$this->module->id]);
        $variant = Item::forceCreate(['name'=>'Sizes','price'=>100,'store_id'=>$this->store->id,'module_id'=>$this->module->id,'variations'=>json_encode([['type'=>'Small','stock'=>2,'price'=>100]])]);
        $service = app(ShopQuickActionService::class);
        $gateway = \Mockery::mock(WhatsAppGateway::class);
        $gateway->shouldReceive('sendTextMessage')->times(5)->andReturn([]);
        foreach (["Set price #{$foreign->id} 150", "Set stock #{$variant->id} 4", 'Set price #1 0', 'Set stock #1 1.5', 'Set price #1 1e8'] as $command) {
            $this->assertTrue($service->handle($command, $this->conversation, $this->contact, $gateway));
        }
        $this->assertSame(0, PendingAction::count());
        Queue::assertNothingPushed();
        $this->assertFalse($service->handle('I sell stock pots', $this->conversation, $this->contact, $gateway));
    }
}
