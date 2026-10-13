from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/tests/Hardening/ProductListingFlowTest.php');s=p.read_text();idx=s.index('    public function test_exact_');s=s[:idx]+'''    public function test_extra_photos_acknowledge_progress_done_button_and_fifth_photo_advances(): void
    {
        $media=(int)$this->productData([])['image'];
        $flow=app(ProductListingFlow::class);$d=$flow->start($this->conversation,$this->contact);
        $data=['image'=>$media,'media_id'=>$media,'name'=>'Earphones','description'=>'Wireless earphones','category_id'=>$this->category->id,'unit_id'=>null,'price'=>13000,'discount'=>0,'stock'=>30,'attribute_ids'=>[],'extra_details'=>0];
        $d->update(['data'=>$data,'step'=>'additional_media']);
        $reply=$flow->receive($this->conversation,$this->contact,new WhatsAppMessage(['type'=>'image','media_id'=>$media]));
        $this->assertStringContainsString('1 of 5',$reply);
        $this->assertStringContainsString('Done with photos',$reply);
        $this->say('done');$this->assertSame('review',$d->fresh()->step);
        $photos=[$media];
        for($i=0;$i<4;$i++){
            $copy=\\Modules\\WhatsAppVendorConcierge\\app\\Models\\WhatsAppMedia::find($media)->replicate();$copy->whatsapp_media_id='extra-'.$i;$copy->save();
            WhatsAppMessage::create(['conversation_id'=>$this->conversation->id,'media_id'=>$copy->id,'direction'=>'inbound','type'=>'image','content'=>[],'whatsapp_message_id'=>'extra-'.$i]);$photos[]=$copy->id;
        }
        $d->refresh()->update(['step'=>'additional_media','data'=>$data+['additional_media'=>array_slice($photos,0,4)]]);
        $flow->receive($this->conversation,$this->contact,new WhatsAppMessage(['type'=>'image','media_id'=>$photos[4]]));
        $this->assertSame('review',$d->fresh()->step);
        $flow->receive($this->conversation,$this->contact,new WhatsAppMessage(['type'=>'image','media_id'=>$photos[4]]));
        $this->assertSame($media,$d->fresh()->data['media_id']);
        $this->assertCount(5,$d->fresh()->data['additional_media']);
    }

    public function test_controls_are_scoped_to_question_and_paginate_without_losing_choices(): void
    {
        $flow=app(ProductListingFlow::class);$d=$flow->start($this->conversation,$this->contact);
        $d->update(['step'=>'extra_details']);
        $presenter=app(\\Modules\\WhatsAppVendorConcierge\\app\\Services\\ProductListingPresenter::class);
        $gateway=\\Mockery::mock(\\Modules\\WhatsAppVendorConcierge\\app\\Services\\WhatsAppGateway::class);
        $gateway->shouldReceive('sendButtonMessage')->once()->withArgs(function($phone,$body,$buttons){return count($buttons)===2 && $buttons[0]['title']==='Yes';})->andReturn([]);
        $presenter->send($gateway,'123',$flow->prompt($d),$d);
        $id=$presenter::id($d,'yes');$d->update(['step'=>'price']);
        $reply=$flow->receive($this->conversation,$this->contact,new WhatsAppMessage(['type'=>'interactive_button','content'=>['interactive'=>['button_reply'=>['id'=>$id]]]]));
        $this->assertStringContainsString('earlier step',$reply);$this->assertSame('price',$d->fresh()->step);
        for($i=0;$i<14;$i++)DB::table('units')->insert(['unit'=>'Unit '.$i]);
        $d->update(['step'=>'unit_id']);
        $gateway->shouldReceive('sendListMessage')->once()->withArgs(function($phone,$body,$sections){return count($sections[0]['rows'])===9 && $sections[0]['rows'][8]['title']==='More options →';})->andReturn([]);
        $presenter->send($gateway,'123','Choose a unit',$d);
        $gateway->shouldReceive('sendListMessage')->once()->withArgs(function($phone,$body,$sections){return count($sections[0]['rows'])===8 && $sections[0]['rows'][6]['title']==='Skip this step';})->andReturn([]);
        $presenter->send($gateway,'123','Choose a unit',$d,1);
    }

    public function test_empty_album_events_do_not_prompt_or_change_progress(): void
    {
        $flow=app(ProductListingFlow::class);$d=$flow->start($this->conversation,$this->contact);
        $gateway=\\Mockery::mock(\\Modules\\WhatsAppVendorConcierge\\app\\Services\\WhatsAppGateway::class);
        $job=new \\Modules\\WhatsAppVendorConcierge\\app\\Jobs\\ProcessIncomingWhatsAppMessage(['id'=>'empty-event','from'=>'123'],[]);
        $job->handle($gateway,app(\\Modules\\WhatsAppVendorConcierge\\app\\Services\\VendorOnboardingService::class),app(\\Modules\\WhatsAppVendorConcierge\\app\\Services\\ConversationManager::class));
        $this->assertSame('image',$d->fresh()->step);$this->assertSame(0,$d->fresh()->stalled_turns);
    }

'''+s[idx:];p.write_text(s)
