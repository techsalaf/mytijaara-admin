from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/tests/Hardening/ProductListingFlowTest.php');s=p.read_text().replace("$d->update(['step'=>'unit_id']);", "$d->update(['step'=>'unit_id']);$d->refresh();");p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ProductListingPresenter.php');s=p.read_text().replace("            'unit_id' =>", "            'attribute_ids' => DB::table('attributes')->orderBy('id')->get(['id','name']),\n            'unit_id' =>");p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/tests/Hardening/ConversationNavigationTest.php');s=p.read_text();idx=s.index('    #[Test]');s=s[:idx]+'''    #[Test]
    public function ordinary_onboarding_answers_never_go_to_ai_navigation(): void
    {
        [$contact,$session,$conversation]=$this->application();
        $conversation->update(['state'=>'onboarding_active','current_step'=>'location']);
        $ai=\\Mockery::mock(\\Modules\\WhatsAppVendorConcierge\\app\\Services\\AiFallbackService::class);
        $router=new \\Modules\\WhatsAppVendorConcierge\\app\\Services\\ConversationOrchestrator(app(ConversationManager::class),$ai);
        $this->assertFalse($router->routeMessage($conversation,$contact,new WhatsAppMessage(['type'=>'text','raw_text'=>'7 Adeji road A, Ibadan']),$this->gateway()));
    }

    #[Test]
    public function optional_onboarding_upload_has_scoped_skip_button(): void
    {
        [$contact,$session,$conversation]=$this->application();
        $gateway=$this->gateway();
        $gateway->shouldReceive('sendButtonMessage')->once()->withArgs(function($to,$body,$buttons)use($session){return $buttons[0]['id']==='onb:'.$session->id.':cover_branding:skip';})->andReturn([]);
        app(VendorOnboardingService::class)->sendStepPrompt($conversation,$contact,'cover_branding',$gateway);
    }

'''+s[idx:];p.write_text(s)
