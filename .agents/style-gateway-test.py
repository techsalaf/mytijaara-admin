from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/tests/Hardening/ConversationNavigationTest.php');s=p.read_text();idx=s.index('    #[Test]');s=s[:idx]+'''    #[Test]
    public function formatted_long_button_messages_keep_details_and_meta_limits(): void
    {
        $history=[];
        $stack=HandlerStack::create(new MockHandler([new Response(200,[],json_encode(['messages'=>[['id'=>'text-part']]])),new Response(200,[],json_encode(['messages'=>[['id'=>'button-part']]]))]));
        $stack->push(Middleware::history($history));
        $gateway=app(WhatsAppGateway::class);
        (new \\ReflectionProperty($gateway,'client'))->setValue($gateway,new Client(['handler'=>$stack,'base_uri'=>'https://graph.facebook.com/v21.0/']));
        $gateway->sendButtonMessage('2348000000000','**Details**'."\\n\\n".str_repeat('Product information. ',70),[['id'=>'stable_id','title'=>'Continue']]);
        $this->assertCount(2,$history);
        $text=json_decode((string)$history[0]['request']->getBody(),true);
        $button=json_decode((string)$history[1]['request']->getBody(),true);
        $this->assertStringStartsWith('💬 *Details*',$text['text']['body']);
        $this->assertLessThanOrEqual(1024,mb_strlen($button['interactive']['body']['text']));
        $this->assertSame('stable_id',$button['interactive']['action']['buttons'][0]['reply']['id']);
    }

'''+s[idx:];p.write_text(s)
