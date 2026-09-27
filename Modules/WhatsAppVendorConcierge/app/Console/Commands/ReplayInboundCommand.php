<?php
namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;
use Illuminate\Console\Command;
use Modules\WhatsAppVendorConcierge\app\Models\{InboundReceipt, WhatsAppMessage};
use Modules\WhatsAppVendorConcierge\app\Jobs\ProcessIncomingWhatsAppMessage;
class ReplayInboundCommand extends Command {
    protected $signature = 'whatsapp:replay-inbound {message_id : Local inbound message ID} {--execute : Queue the reviewed replay; otherwise preview only}';
    protected $description = 'Preview or replay a recent message whose receipt proves business processing never started';
    public function handle(): int {
        $message = WhatsAppMessage::with('conversation.contact')->find($this->argument('message_id'));
        $receipt = $message ? InboundReceipt::where('message_id', $message->id)->first() : null;
        if (!$message || $message->direction !== 'inbound' || !$receipt || $receipt->phase !== 'received'
            || $message->created_at->lt(now()->subHours(24)) || !$message->conversation?->contact
            || $message->conversation->contact->is_blocked || str_contains((string) $message->raw_text, '[redacted:')) {
            $this->error('Replay excluded: missing/unsafe checkpoint, expired message, blocked contact or redacted credentials.');
            return self::FAILURE;
        }
        $conversation = $message->conversation;
        $version = hash('sha256', json_encode([$conversation->state, $conversation->current_step, $conversation->onboarding_session_id, $conversation->updated_at?->toISOString()]));
        if ($receipt->state_version !== $version || $conversation->messages()->where('direction', 'inbound')->where('id', '>', $message->id)->exists()) {
            $this->error('Conversation changed since receipt. Inspect the current step; do not replay an old answer.');
            return self::FAILURE;
        }
        $content = $message->content;
        $type = str_starts_with($message->type, 'interactive') ? 'interactive' : $message->type;
        if (!empty($content['button'])) $type = 'button';
        $payload = array_merge($content, ['id'=>$message->whatsapp_message_id, 'from'=>$conversation->contact->whatsapp_id, 'type'=>$type]);
        if ($type === 'text') $payload['text'] = ['body' => $message->raw_text];
        if ($this->option('execute')) {
            ProcessIncomingWhatsAppMessage::dispatch($payload, [])->onQueue(config('whatsapp-vendor-concierge.queue.jobs.process_incoming', 'whatsapp'));
            $this->info('Replay queued; the worker rechecks the checkpoint before processing.');
        } else $this->info('Eligible checkpoint. Add --execute after reviewing the conversation. No message was sent.');
        return self::SUCCESS;
    }
}
