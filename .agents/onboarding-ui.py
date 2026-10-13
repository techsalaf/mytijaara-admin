from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/VendorOnboardingService.php');s=p.read_text();needle="        $prompt = $prompts[$step] ?? ['text' => 'Please continue...'];";s=s.replace(needle,needle+'''
        if (in_array($step, ['cover_branding','kyc_documents'],true) && $session) {
            $prompt = ['type'=>'button','body'=>$prompt['text'],'buttons'=>[
                ['id'=>'onb:'.$session->id.':'.$step.':skip','title'=>'Skip for now'],
                ['id'=>'talk_support','title'=>'Talk to Support'],
            ]];
        }
        if (($prompt['type'] ?? '') === 'list') {
            $rows = collect($prompt['sections'])->flatMap(fn($section)=>$section['rows'] ?? [])->values();
            if ($rows->count()>0 && $rows->count()<=3) {
                $prompt['type']='button';
                $prompt['buttons']=$rows->map(fn($row)=>['id'=>$row['id'],'title'=>$row['title']])->all();
            }
        }
        if (($prompt['type'] ?? '') === 'button' && mb_strlen($prompt['body'])>1000) {
            for($i=0;$i<mb_strlen($prompt['body']);$i+=3500)$gateway->sendTextMessage($contact->phone_number,mb_substr($prompt['body'],$i,3500));
            $prompt['body']='✅ Review your application above. Submit when everything is correct, or choose Edit.';
        }
''');p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/app/Jobs/ProcessIncomingWhatsAppMessage.php');s=p.read_text();needle='        if ($buttonId) {';s=s.replace(needle,'''        if ($buttonId && preg_match('/^onb:(\\d+):(cover_branding|kyc_documents):skip$/',$buttonId,$choice)) {
            if ((int)$conversation->onboarding_session_id !== (int)$choice[1] || $conversation->current_step !== $choice[2]) {
                $gateway->sendTextMessage($contact->phone_number,'That option is from an earlier question. Please use the latest message.');
                return;
            }
            $answer=clone $message;
            $answer->raw_text='skip';$answer->type='text';$answer->content=['text'=>'skip'];
            $onboardingService->processStep($conversation,$contact,$answer,$gateway);
            return;
        }

'''+needle);p.write_text(s)
