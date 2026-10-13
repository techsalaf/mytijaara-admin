from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ProductListingFlow.php');s=p.read_text(encoding='utf-8').replace('::dispatch($draft->id,$message->id)', '::dispatch($draft->id,$message->id,$draft->step)').replace("->onConnection(config('whatsapp-vendor-concierge.queue.connection','database'))", "->onConnection(config('whatsapp-vendor-concierge.queue.connection','database')==='sync'?'database':config('whatsapp-vendor-concierge.queue.connection','database'))");p.write_text(s,encoding='utf-8')
p=Path('Modules/WhatsAppVendorConcierge/app/Jobs/ResumeProductDraftMessage.php');s=p.read_text(encoding='utf-8').replace('public int $messageId){}','public int $messageId,public string $expectedStep){}').replace("  $c=$draft->conversation;", "  if($draft->step!==$this->expectedStep){$draft->update(['needs_attention'=>true,'errors'=>['processing'=>['A reply arrived while the draft advanced; review the saved inbound message before applying it.']]]);return;}\n  $c=$draft->conversation;");p.write_text(s,encoding='utf-8')
p=Path('Modules/WhatsAppVendorConcierge/tests/Hardening/ProductListingFlowTest.php');s=p.read_text(encoding='utf-8');idx=s.rfind('\n}');s=s[:idx]+'''
    public function test_busy_draft_queues_the_stored_reply_independently_of_webhook_deduplication(): void
    {
        $flow=app(ProductListingFlow::class);$draft=$flow->start($this->conversation,$this->contact);
        $message=WhatsAppMessage::create(['conversation_id'=>$this->conversation->id,'whatsapp_message_id'=>'busy-reply','direction'=>'inbound','type'=>'text','raw_text'=>'Blue Bag','content'=>['text'=>'Blue Bag']]);
        $busy=\\Mockery::mock(ProductListingFlow::class,[app(ProductFieldMap::class)])->makePartial();
        $busy->shouldReceive('receive')->once()->andThrow(new \\Illuminate\\Contracts\\Cache\\LockTimeoutException());
        $this->assertStringContainsString('reply is saved',$busy->receiveOrDefer($this->conversation,$this->contact,$message));
        \\Illuminate\\Support\\Facades\\Queue::assertPushed(\\Modules\\WhatsAppVendorConcierge\\app\\Jobs\\ResumeProductDraftMessage::class,fn($job)=>$job->draftId===$draft->id && $job->messageId===$message->id);
    }
''' +s[idx:];p.write_text(s,encoding='utf-8')
p=Path('Modules/WhatsAppVendorConcierge/docs/product-listing/handoff.md');s=p.read_text(encoding='utf-8');s+='\nConcurrency follow-up: busy draft inputs are deferred as `ResumeProductDraftMessage` on the existing incoming-message worker queue, independently of webhook receipt deduplication. The stored reply is retried with bounded backoff; changed step/ownership flags admin attention rather than applying an old answer to a new question. Images persist before the external vision call.\n';p.write_text(s,encoding='utf-8')
