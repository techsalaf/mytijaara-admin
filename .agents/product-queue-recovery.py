from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ProductListingFlow.php');s=p.read_text(encoding='utf-8');needle='    public function receive(WhatsAppConversation';idx=s.index(needle);s=s[:idx]+'''    public function receiveOrDefer(WhatsAppConversation $c,WhatsAppContact $contact,WhatsAppMessage $message): string
    {
        try {return $this->receive($c,$contact,$message);}
        catch (\\Illuminate\\Contracts\\Cache\\LockTimeoutException $e) {
            $draft=$this->current($c);
            if(!$draft || !$message->exists)throw $e;
            \\Modules\\WhatsAppVendorConcierge\\app\\Jobs\\ResumeProductDraftMessage::dispatch($draft->id,$message->id)
                ->onConnection(config('whatsapp-vendor-concierge.queue.connection','database'))
                ->onQueue(config('whatsapp-vendor-concierge.queue.jobs.process_incoming','whatsapp.process_incoming'))->delay(now()->addSeconds(5));
            return 'Your image or previous answer is still being processed. Your new reply is saved and will continue automatically.';
        }
    }

'''+s[idx:];p.write_text(s,encoding='utf-8')
for name in ['ConversationManager.php','ConversationOrchestrator.php']:
 p=Path('Modules/WhatsAppVendorConcierge/app/Services')/name;s=p.read_text(encoding='utf-8').replace('->receive($conversation, $contact, $message)','->receiveOrDefer($conversation, $contact, $message)').replace('->receive($conversation,$contact,$message)','->receiveOrDefer($conversation,$contact,$message)');p.write_text(s,encoding='utf-8')
