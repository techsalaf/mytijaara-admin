from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ConversationOrchestrator.php');s=p.read_text();a=s.index('        // We only invoke AI orchestration');b=s.index('        // Loop detection',a);s=s[:a]+s[b:];p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/tests/Hardening/ProductListingFlowTest.php');s=p.read_text();idx=s.index('    public function test_empty_album');s=s[:idx]+'''    public function test_product_button_is_routed_before_generic_onboarding_buttons(): void
    {
        $flow=app(ProductListingFlow::class);$d=$flow->start($this->conversation,$this->contact);
        $d->update(['step'=>'extra_details']);
        $id=\\Modules\\WhatsAppVendorConcierge\\app\\Services\\ProductListingPresenter::id($d,'yes');
        $message=new WhatsAppMessage(['type'=>'interactive_button','content'=>['interactive'=>['button_reply'=>['id'=>$id]]]]);
        $gateway=\\Mockery::mock(\\Modules\\WhatsAppVendorConcierge\\app\\Services\\WhatsAppGateway::class);
        $gateway->shouldReceive('sendButtonMessage')->once()->andReturn([]);
        $job=new \\Modules\\WhatsAppVendorConcierge\\app\\Jobs\\ProcessIncomingWhatsAppMessage([],[]);
        (new \\ReflectionMethod($job,'processByState'))->invoke($job,$this->conversation,$this->contact,$message,app(\\Modules\\WhatsAppVendorConcierge\\app\\Services\\VendorOnboardingService::class),app(\\Modules\\WhatsAppVendorConcierge\\app\\Services\\ConversationManager::class),$gateway);
        $this->assertSame(1,$d->fresh()->data['extra_details']);
    }

'''+s[idx:];p.write_text(s)
