<?php
namespace Modules\WhatsAppVendorConcierge\app\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
use Modules\WhatsAppVendorConcierge\app\Models\{ProductListingDraft,WhatsAppMessage};
use Modules\WhatsAppVendorConcierge\app\Services\{ProductListingFlow,WhatsAppGateway};
/** Retry stored draft input independently of the inbound webhook's receipt deduplication. */
class ResumeProductDraftMessage implements ShouldQueue {
 use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
 public int $tries=8;
 public int $timeout=120;
 public function __construct(public int $draftId,public int $messageId,public string $expectedStep){}
 public function backoff(): array{return [5,10,20,30,60];}
 public function handle(ProductListingFlow $flow,WhatsAppGateway $gateway): void {
  $draft=ProductListingDraft::find($this->draftId);$message=WhatsAppMessage::find($this->messageId);
  if(!$draft || !$message || $draft->status!=='active' || $message->conversation_id!==$draft->conversation_id)return;
  if($draft->step!==$this->expectedStep){$draft->update(['needs_attention'=>true,'errors'=>['processing'=>['A reply arrived while the draft advanced; review the saved inbound message before applying it.']]]);return;}
  $c=$draft->conversation;$contact=$c->contact;
  if($c->state!=='ai_active'||$contact->is_blocked){$draft->update(['needs_attention'=>true,'errors'=>['delivery'=>['A saved reply needs review after conversation ownership changed.']]]);return;}
  $reply=$flow->receive($c,$contact,$message);$flow->sendReply($gateway,$contact->phone_number,$reply);
 }
 public function failed(?\Throwable $error): void {ProductListingDraft::find($this->draftId)?->update(['needs_attention'=>true,'errors'=>['processing'=>['A stored reply could not be processed after retries. Review the conversation.']]]);}
}
