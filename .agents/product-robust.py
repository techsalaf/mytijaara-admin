from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ProductListingFlow.php');s=p.read_text(encoding='utf-8')
s=s.replace("if(in_array($cmd,['help'", "if($cmd==='continue without ai')$d->vision=['status'=>'disabled_by_vendor'];\n   if(in_array($cmd,['help'")
s=s.replace("$d->vision=app(ProductVisionService::class)->analyse($media,$d->store);", "$d->vision=in_array($d->vision['status']??'',['disabled_by_vendor','disabled_by_admin'],true)?$d->vision:app(ProductVisionService::class)->analyse($media,$d->store);")
s=s.replace("     $media=app(ProductMedia::class)->owned", "     if(!$pending)throw ValidationException::withMessages(['image'=>'The image reference is missing. Please upload it again.']);\n     $media=app(ProductMedia::class)->owned",1)
s=s.replace("$sources=$d->sources;$sources[$d->step]='canonical default", "if($d->step==='image'){$data=$d->data;unset($data['media_id']);$d->data=$data;}\n     $sources=$d->sources;$sources[$d->step]='canonical default")
s=s.replace("$data=$locked->data;unset", "abort_unless((int)$locked->store->module_id===(int)$locked->module_id && $locked->store->status && (int)$locked->store->vendor->status===1,409,'Shop status or module changed; contact support to review this draft.');\n   $data=$locked->data;unset")
s=s.replace("AI suggestion (unconfirmed): ", "AI suggestion (unconfirmed; check carefully): ")
s=s.replace("   $reply", "   $reply")
# Each long review remains complete across WhatsApp's 4096 character limit.
s=s.replace(" public function current(",''' public function sendReply(WhatsAppGateway $gateway,string $phone,string $reply): void {
  while($reply!==''){$chunk=mb_substr($reply,0,3500);$reply=mb_substr($reply,3500);$gateway->sendTextMessage($phone,$chunk);}
 }
 public function current(''')
p.write_text(s,encoding='utf-8')
for name in ['ConversationManager.php','ConversationOrchestrator.php']:
 p=Path('Modules/WhatsAppVendorConcierge/app/Services')/name;s=p.read_text(encoding='utf-8').replace("if ($reply !== '') $gateway->sendTextMessage($contact->phone_number, $reply);", "if ($reply !== '') app(ProductListingFlow::class)->sendReply($gateway,$contact->phone_number,$reply);").replace("if ($reply !== '') $gateway->sendTextMessage($contact->phone_number,$reply);", "if ($reply !== '') app(ProductListingFlow::class)->sendReply($gateway,$contact->phone_number,$reply);");p.write_text(s,encoding='utf-8')
