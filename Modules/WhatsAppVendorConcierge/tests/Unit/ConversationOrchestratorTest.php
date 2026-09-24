<?php

namespace Modules\WhatsAppVendorConcierge\tests\Unit;

use Tests\TestCase;
use Modules\WhatsAppVendorConcierge\app\Services\ConversationOrchestrator;
use Modules\WhatsAppVendorConcierge\app\Services\ConversationManager;
use Modules\WhatsAppVendorConcierge\app\Services\AiFallbackService;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;
use Modules\WhatsAppVendorConcierge\app\Services\ConversationCommands;
use Mockery;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ConversationOrchestratorTest extends TestCase
{
    // use RefreshDatabase;

    public function test_deterministic_routing_works()
    {
        $manager = Mockery::mock(ConversationManager::class);
        $aiService = Mockery::mock(AiFallbackService::class);
        
        $orchestrator = new ConversationOrchestrator($manager, $aiService);
        
        $contact = new WhatsAppContact(['phone_number' => '123']);
        $conversation = new WhatsAppConversation(['contact_id' => 1]);
        $message = new WhatsAppMessage(['raw_text' => 'help']);
        $gateway = Mockery::mock(WhatsAppGateway::class);
        
        $manager->shouldReceive('showHelp')->once();
        
        $result = $orchestrator->routeMessage($conversation, $contact, $message, $gateway);
        
        $this->assertTrue($result);
    }
}
