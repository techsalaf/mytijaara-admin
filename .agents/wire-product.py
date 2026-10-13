from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services')
f=p/'ConversationManager.php';s=f.read_text(encoding='utf-8');a=s.index('        // If vendor sends a photo');b=s.index('        // Queue AI processing',a);s=s[:a]+'''        $flow = app(ProductListingFlow::class);
        if ($message->type === 'image' || $flow->handles($conversation, (string)$message->raw_text)) {
            $reply = $flow->receive($conversation, $contact, $message);
            if ($reply !== '') $gateway->sendTextMessage($contact->phone_number, $reply);
            return;
        }

'''+s[b:];a=s.index('        $gateway->sendTextMessage(',s.index('    protected function handleAddProducts'));b=s.index('\n    }',a);s=s[:a]+'''        $flow=app(ProductListingFlow::class);
        $draft=$flow->start($conversation,$contact);
        $draft->update(['status'=>'active']);
        $gateway->sendTextMessage($contact->phone_number,$flow->prompt($draft));'''+s[b:];f.write_text(s,encoding='utf-8')
f=p/'ConversationOrchestrator.php';s=f.read_text(encoding='utf-8');a=s.index('        // Deterministic routing first');s=s[:a]+'''        // Product answers must not be reclassified by the general AI router.
        if ($contact->vendor_id && $conversation->state === 'ai_active'
            && !in_array(ConversationCommands::action($text), ['support','shop_details','status'], true)
            && app(ProductListingFlow::class)->handles($conversation,$text)) {
            $reply=app(ProductListingFlow::class)->receive($conversation,$contact,$message);
            if ($reply !== '') $gateway->sendTextMessage($contact->phone_number,$reply);
            return true;
        }

'''+s[a:];f.write_text(s,encoding='utf-8')
f=p/'ProductListingFlow.php';s=f.read_text(encoding='utf-8');s=s.replace("$d=$this->current($c)??$this->start($c,$contact);",'''$d=$this->current($c);
  if(!$d && in_array(strtolower(trim((string)$m->raw_text)),['confirm','confirm and create'],true)) {
   $completed=Draft::where('conversation_id',$c->id)->whereNotNull('item_id')->latest('id')->first();
   return $completed?'Already created: product #'.$completed->item_id:'No product is awaiting confirmation. Choose Add Products.';
  }
  $d??=$this->start($c,$contact);''');s=s.replace("'add product','add new product','resume product'],true)","'add product','add new product','resume product','confirm','confirm and create'],true)")
s=s.replace("   if($cmd==='back'",'''   if(str_starts_with($cmd,'options'))return $this->options($d,trim(substr($text,7)));
   if($cmd==='back' ''')
s=s.replace("$sources[$f]='vendor';", "$sources[$f]=isset($d->vision['suggestions'][$f]) && (string)$d->vision['suggestions'][$f]===(string)$v?'AI suggestion confirmed by vendor':'vendor';")
s=s.replace("$s=Store::where('vendor_id',$contact->vendor_id)->firstOrFail();", "$s=Store::where('vendor_id',$contact->vendor_id)->firstOrFail();\n  abort_unless((int)$s->vendor->status===1 && $s->status,403,'Only approved stores can list products.');")
f.write_text(s,encoding='utf-8')
