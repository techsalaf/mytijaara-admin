from pathlib import Path
base=Path('Modules/WhatsAppVendorConcierge/app')
p=base/'Services/WhatsAppGateway.php';s=p.read_text()
for sig in ["public function sendTextMessage(string $to, string $body, ?string $previewUrl = null): array", "public function sendButtonMessage(string $to, string $body, array $buttons, ?string $header = null, ?string $footer = null): array", "public function sendListMessage(string $to, string $body, array $sections, ?string $header = null, ?string $footer = null, string $buttonText = 'Select'): array"]:
 s=s.replace(sig+'\n    {',sig+'\n    {\n        $body = WhatsAppCopy::format($body);')
p.write_text(s)
p=base/'Jobs/RunVendorAiConversation.php';s=p.read_text().replace('    protected function sendReply(string $text): void\n    {','    protected function sendReply(string $text): void\n    {\n        $text = \\Modules\\WhatsAppVendorConcierge\\app\\Services\\WhatsAppCopy::format($text);');p.write_text(s)
p=base/'Agents/VendorConciergeAgent.php';s=p.read_text().replace('• Use WhatsApp-friendly formatting: **bold**, *italic*, emojis for visual scanning','• Every customer-facing answer must include a relevant, restrained emoji (for example 🛍️, 📦, 💰 or 💬). Use blank lines between sections and • bullets for lists.\n• Use native WhatsApp *bold* and _italic_, not Markdown **bold**, tables or heading syntax. Keep paragraphs short and the next action clear.').replace('→ Explain that product creation is completed in the vendor dashboard and offer to help with product details.','→ Tell the vendor to select Add Products or send Add new product to start the guided WhatsApp listing flow. The dashboard is also available.');p.write_text(s)
p=base/'Services/ConversationManager.php';s=p.read_text();start=s.index("            'manage_shop' =>");end=s.index("            'learn_selling'",start);s=s[:start]+"            'manage_shop' => $this->showManageShop($conversation, $contact, $gateway),\n"+s[end:]
pos=s.index('    public function showShopDetails(')
s=s[:pos]+'''    public function showManageShop(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        $store=$contact->vendor?->store;
        if (!$store || (int)$contact->vendor?->status !== 1) { $this->checkApplicationStatus($conversation,$contact,$gateway); return; }
        $details=app(VendorAccessNoticeService::class)->details($store);
        $gateway->sendCtaUrlMessage($contact->phone_number,
            "🏪 *Manage {$details['name']}*\\n\\nYour vendor dashboard lets you:\\n• 📦 Manage products and inventory\\n• 🧾 Review orders and sales\\n• ⚙️ Update your shop profile and settings\\n\\n🔐 Sign in with your registered email and password. Use *Forgot Password* if needed.\\n\\n💬 You can also keep chatting here—try *Add new product* or *Show my shop details*.",
            'Open dashboard', $details['login_url'], 'MyTijaara');
    }

'''+s[pos:];p.write_text(s)
p=base/'Services/ConversationCommands.php';s=p.read_text().replace("            'shop_details' =>", "            'manage_shop' => ['manage shop', 'manage my shop', 'manage_shop', 'vendor dashboard', 'open dashboard'],\n            'shop_details' =>");p.write_text(s)
p=base/'Services/ConversationOrchestrator.php';s=p.read_text().replace("            'shop_details' =>", "            'manage_shop' => $this->manager->showManageShop($conversation, $contact, $gateway),\n            'shop_details' =>");s=s.replace("['support','shop_details','status']","['support','shop_details','manage_shop','status']");p.write_text(s)
p=base/'Services/VendorAccessNoticeService.php';s=p.read_text();a=s.index('        return "Hello!');b=s.index(';',a);s=s[:a]+'''        return "🏪 *Your MyTijaara shop*\\n\\n*{$d['name']}*\\n• 🛍️ Module: {$d['module']}\\n• 📍 Dispatch address: {$d['address']}\\n• 📋 Plan: {$d['plan']}\\n• 🚚 Delivery: {$d['delivery']}\\n\\n🔐 *Dashboard access*\\n• Login email: {$d['email']}\\n{$d['login_url']}\\n\\nUse your existing password or *Forgot Password*. Never share your password in WhatsApp.\\n\\n📱 *Quick access*\\nAdd the login page to your phone’s Home Screen. We’re working on the vendor mobile app and will announce when it is ready.\\n\\n💬 *Human support*\\n{$d['support_url']}"'''+s[b:];p.write_text(s)
