from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ConversationManager.php');s=p.read_text();a=s.index('        $support = app(SupportCaseService::class);',s.index('public function initiateHumanHandoff'));b=s.index('\n    }',a);s=s[:a]+'''        $number = preg_replace('/\\D+/', '', (string) config('whatsapp-vendor-concierge.support.whatsapp_number'));
        if (!$number) {
            $gateway->sendTextMessage($contact->phone_number, 'Please contact '.config('whatsapp-vendor-concierge.support.email').' for support. You can keep using this concierge.');
            return;
        }
        $text = 'Hello MyTijaara Support, I need help with '.($contact->vendor?->store?->name ?? 'my account').'. Reference: WA-'.$conversation->id;
        $url = 'https://wa.me/'.$number.'?text='.rawurlencode($text);
        $gateway->sendCtaUrlMessage($contact->phone_number,
            'Our human support team can help on +'.$number.'. Tap below to open a separate WhatsApp chat, then press Send. You can keep using this concierge for your shop or registration.',
            'Talk to Support', $url);
''' + s[b:];s=s.replace('Assalaamu Alaikum! Welcome back to MyTijaara.','Welcome back to MyTijaara.').replace('Assalaamu Alaikum 👋','Hello 👋');p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/config/config.php');s=p.read_text().replace("    'api' => [","    'support' => ['whatsapp_number' => env('MYTIJAARA_SUPPORT_WHATSAPP', '2347049147825'), 'email' => 'hello@mytijaara.com'],\n\n    'api' => [",1);p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/app/Services/VendorOnboardingService.php');s=p.read_text().replace('Where is your shop physically located?','Where do you operate or dispatch orders from? Online-only and home-based businesses are welcome.').replace('full physical shop address','operating or dispatch address').replace('full physical street address','real operating or dispatch address').replace('[Section 2 of 5: Store Location]','[Section 2 of 5: Business Location]');p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/app/Agents/VendorConciergeAgent.php');s=p.read_text().replace('===== YOUR CAPABILITIES =====','''Use inclusive, neutral greetings such as Hello or Welcome. Never add religious greetings, blessings, or assume a religion. Online-only and home-based businesses can register using a real operating/dispatch address and supported zone. Do not imply a walk-in shop is required. For human support, share https://wa.me/2347049147825?text=Hello%20MyTijaara%20Support%2C%20I%20need%20help. Explain that sending a message there does not stop this concierge. Never claim a mobile app release date.

===== YOUR CAPABILITIES =====''');p.write_text(s)

