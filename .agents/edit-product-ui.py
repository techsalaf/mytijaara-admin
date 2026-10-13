from pathlib import Path
base=Path('Modules/WhatsAppVendorConcierge/app')
for f in ['Services/ConversationManager.php','Services/ConversationOrchestrator.php']:
 p=base/f;s=p.read_text();s=s.replace('sendReply($gateway,$contact->phone_number,$reply);','sendReply($gateway,$contact->phone_number,$reply,$conversation);');s=s.replace('$gateway->sendTextMessage($contact->phone_number,$flow->prompt($draft));','$flow->sendReply($gateway,$contact->phone_number,$flow->prompt($draft),$conversation);');p.write_text(s)
p=base/'Jobs/ResumeProductDraftMessage.php';s=p.read_text().replace('sendReply($gateway,$contact->phone_number,$reply);','sendReply($gateway,$contact->phone_number,$reply,$c);');p.write_text(s)
p=base/'Jobs/ProcessIncomingWhatsAppMessage.php';s=p.read_text();s=s.replace("        $messageId = $this->messageData['id'] ?? null;", """        // Album/status/unknown events without a user message must not answer draft questions.
        if (empty($this->messageData['type']) || in_array($this->messageData['type'], ['reaction','system','unsupported'], true)) return;
        $messageId = $this->messageData['id'] ?? null;""")
needle='        // 1. Check for interactive button replies (Meta payload or parsed message)'
insert='''        $productSelection = \\Modules\\WhatsAppVendorConcierge\\app\\Services\\ProductListingPresenter::selection($message);
        if ($productSelection !== null && $vendor && (int)$vendor->status === 1) {
            $flow = app(\\Modules\\WhatsAppVendorConcierge\\app\\Services\\ProductListingFlow::class);
            $draft = $flow->current($conversation);
            if (!$draft) {
                $gateway->sendTextMessage($contact->phone_number, 'This product draft is already closed. Open Add Products to start another.');
                return;
            }
            $answer = \\Modules\\WhatsAppVendorConcierge\\app\\Services\\ProductListingPresenter::decode($draft,$productSelection);
            if ($answer !== null && preg_match('/^page:(\\d+)$/',$answer,$page)) {
                $flow->sendReply($gateway,$contact->phone_number,$flow->prompt($draft),$conversation,(int)$page[1]);
            } else {
                $reply=$flow->receiveOrDefer($conversation,$contact,$message);
                $flow->sendReply($gateway,$contact->phone_number,$reply,$conversation);
            }
            return;
        }

'''
s=s.replace(needle,insert+needle);p.write_text(s)
