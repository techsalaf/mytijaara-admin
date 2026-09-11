<?php

namespace Modules\WhatsAppVendorConcierge\tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppFlow;
use Tests\TestCase;

class WhatsAppFlowTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function it_creates_and_queries_flows()
    {
        $flowId = 'flow_' . uniqid();
        $flow = WhatsAppFlow::create([
            'flow_id' => $flowId,
            'name' => 'vendor_onboarding',
            'version' => '2.1',
            'status' => 'published',
            'screens' => [
                ['id' => 'SCREEN_1', 'title' => 'Business Details'],
                ['id' => 'SCREEN_2', 'title' => 'Category'],
            ],
            'published_at' => now(),
        ]);

        $this->assertNotNull($flow);
        $this->assertEquals($flowId, $flow->flow_id);
        $this->assertEquals(2, count($flow->screens));

        // Test scopePublished
        $published = WhatsAppFlow::published()->where('flow_id', $flowId)->first();
        $this->assertNotNull($published);

        // Test findActiveByName
        $activeFlow = WhatsAppFlow::findActiveByName('vendor_onboarding');
        $this->assertNotNull($activeFlow);
        $this->assertEquals('vendor_onboarding', $activeFlow->name);

        // Test getScreen
        $screen = $flow->getScreen('SCREEN_1');
        $this->assertNotNull($screen);
        $this->assertEquals('Business Details', $screen['title']);

        $nonExistentScreen = $flow->getScreen('INVALID_SCREEN');
        $this->assertNull($nonExistentScreen);
    }
}
