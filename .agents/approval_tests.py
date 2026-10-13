from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/tests/Unit/AdminApprovalNotificationTest.php')
s=p.read_text(encoding='utf-8').replace('use Modules\\WhatsAppVendorConcierge\\app\\Jobs\\SendWhatsAppMessage;', 'use Modules\\WhatsAppVendorConcierge\\app\\Jobs\\SendVendorStatusNotification;')
s=s.replace("'status' => 0,", "'status' => null,")
s=s.replace('        $contact = WhatsAppContact::create([', '''        $store = Store::forceCreate(['name' => 'Applicant shop', 'phone' => $phone,
            'vendor_id' => $vendor->id, 'status' => 0]);
        $contact = WhatsAppContact::create([''')
s=s.replace("        $vendor->rejection_note = 'Invalid CAC document provided.';", "        $vendor->rejection_note = 'Invalid CAC document provided.';")
a=s.index('        Queue::assertPushed(SendWhatsAppMessage::class,')
b=s.index('\n\n        $conversation->refresh();',a)
s=s[:a]+'''        Queue::assertPushed(SendVendorStatusNotification::class, fn ($job) =>
            $job->storeId === $store->id && $job->status === 'approved');
        Queue::assertPushed(SendVendorStatusNotification::class, 1);'''+s[b:]
a=s.index('        Queue::assertPushed(SendWhatsAppMessage::class,')
b=s.index('\n\n        $conversation->refresh();',a)
s=s[:a]+'''        Queue::assertPushed(SendVendorStatusNotification::class, fn ($job) =>
            $job->storeId === $store->id && $job->status === 'denied'
                && $job->rejectionNote === 'Invalid CAC document provided.');
        Queue::assertPushed(SendVendorStatusNotification::class, 1);'''+s[b:]
s=s.replace("$this->assertEquals('closed', $conversation->state);", "$this->assertEquals('welcome', $conversation->state);")
p.write_text(s,encoding='utf-8')
p=Path('Modules/WhatsAppVendorConcierge/tests/Unit/VendorStatusNotificationJobTest.php')
s=p.read_text(encoding='utf-8')
s=s.replace("        $gateway = $this->createMock(WhatsAppGateway::class);", """        $activeConversation = WhatsAppConversation::where('contact_id', WhatsAppContact::where('vendor_id', $vendor->id)->value('id'))->firstOrCreate([
            'contact_id' => WhatsAppContact::where('vendor_id', $vendor->id)->value('id'),
        ]);
        \\Modules\\WhatsAppVendorConcierge\\app\\Models\\WhatsAppMessage::create([
            'conversation_id' => $activeConversation->id, 'direction' => 'inbound',
            'type' => 'text', 'content' => ['text' => 'Hi'], 'whatsapp_message_id' => 'inbound-'.$vendor->id,
        ]);
        $gateway = $this->createMock(WhatsAppGateway::class);""")
# Denial uses a support quick reply in the open conversation window.
start=s.index('    public function it_sends_denial_notification')
end=s.index('    public function it_sends_suspension', start)
chunk=s[start:end].replace("->method('sendTextMessage')", "->method('sendButtonMessage')")
chunk=chunk.replace('                })\n            );', "                }),\n                [['id' => 'talk_support', 'title' => 'Talk to Support']]\n            )->willReturn(['messages' => [['id' => 'denied-message']]]);")
s=s[:start]+chunk+s[end:]
s=s.replace('                })\n            );', "                })\n            )->willReturn(['messages' => [['id' => 'approved-message']]]);")
s=s.replace('        // Suspension\n', "        // Suspension\n        $store->update(['status' => 0]);\n")
s=s.replace('        // Reactivation\n', "        // Reactivation\n        $store->update(['status' => 1]);\n")
p.write_text(s,encoding='utf-8')
